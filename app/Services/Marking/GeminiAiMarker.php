<?php

namespace App\Services\Marking;

use App\Models\Recording;
use App\Support\Bands;
use App\Support\Text;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writing and Speaking marking with Google Gemini (MARKING_DRIVER=gemini).
 *
 * The model returns JSON matching a schema; this class turns it into the API contract's shapes.
 * Annotations come back as quoted text and are located in the essay here, because models are bad
 * at counting character offsets. Speaking recordings are sent inline as audio, so transcription
 * and marking happen in one call. Any failure throws: the MarkAttempt job retries, then refunds.
 */
class GeminiAiMarker implements AiMarker
{
    private const CATEGORIES = ['grammar', 'vocabulary', 'spelling', 'punctuation', 'coherence', 'style'];

    /** Gemini's inline request limit is 20 MB; base64 adds a third. */
    private const MAX_AUDIO_BYTES = 14 * 1024 * 1024;

    private const EXAMINER = 'You are an experienced, certified IELTS examiner marking the IELTS General Training test '
        .'against the official public band descriptors. Be accurate and honest: do not inflate bands. '
        .'Use half bands (e.g. 5.5, 6.0, 6.5). Write feedback in clear, simple English for the student, '
        .'addressing them as "you". Be specific and practical.';

    public function __construct(
        private string $apiKey,
        private string $model,
        private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        private int $timeout = 120,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }
    }

    public function markWriting(array $task, string $text): array
    {
        $number = (int) $task['number'];
        $first = $number === 1 ? 'task_achievement' : 'task_response';
        $wordCount = Text::wordCount($text);
        $what = $number === 1 ? 'Task 1 (a letter)' : 'Task 2 (an essay)';
        $taskText = trim($task['prompt']
            ."\n".implode("\n", array_map(fn ($b) => "- {$b}", $task['bullets'] ?? []))
            ."\n".($task['closing'] ?? ''));

        $prompt = <<<PROMPT
            Mark this IELTS General Training Writing {$what}.

            THE TASK (minimum {$task['minWords']} words):
            {$taskText}

            THE STUDENT'S ANSWER ({$wordCount} words):
            <<<
            {$text}
            >>>

            Instructions:
            - Give a band and a 2-3 sentence comment for each of the 4 criteria: {$first}, coherence_cohesion, lexical_resource, grammatical_range.
              Apply the official word-count penalty if the answer is under {$task['minWords']} words.
            - "summary": 2 sentences: the overall level and the single most useful thing to improve.
            - "annotations": up to 15 of the most important errors or weak choices. "quote" must be copied EXACTLY from the answer
              (a few words, enough to be unique). "category" is one of grammar, vocabulary, spelling, punctuation, coherence, style.
              "message" explains the problem in one sentence. "suggestion" is the corrected wording, or an empty string.
            - "improvedVersion": the student's answer rewritten at about Band 8, keeping their ideas and structure.
            PROMPT;

        $schema = [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'criteria' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'enum' => [$first, 'coherence_cohesion', 'lexical_resource', 'grammatical_range']],
                            'band' => ['type' => 'number'],
                            'comment' => ['type' => 'string'],
                        ],
                        'required' => ['key', 'band', 'comment'],
                    ],
                ],
                'annotations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'quote' => ['type' => 'string'],
                            'category' => ['type' => 'string', 'enum' => self::CATEGORIES],
                            'message' => ['type' => 'string'],
                            'suggestion' => ['type' => 'string'],
                        ],
                        'required' => ['quote', 'category', 'message', 'suggestion'],
                    ],
                ],
                'improvedVersion' => ['type' => 'string'],
            ],
            'required' => ['summary', 'criteria', 'annotations', 'improvedVersion'],
        ];

        $out = $this->generate([['text' => $prompt]], $schema);
        $criteria = $this->criteria($out['criteria'] ?? [], [$first, 'coherence_cohesion', 'lexical_resource', 'grammatical_range']);

        return [
            'band' => Bands::round(array_sum(array_column($criteria, 'band')) / 4),
            'summary' => trim((string) ($out['summary'] ?? '')),
            'criteria' => $criteria,
            'annotations' => $this->locate($text, $out['annotations'] ?? []),
            'improvedVersion' => trim((string) ($out['improvedVersion'] ?? '')) ?: null,
        ];
    }

    public function markSpeaking(array $content, Collection $recordings): array
    {
        $byQuestion = $recordings->keyBy('question_id');
        $parts = [['text' => "Mark this IELTS Speaking test. Each recording below is the student's answer to the examiner question shown before it.\n"]];
        $bytes = 0;
        $ids = [];
        foreach ($content['parts'] as $part) {
            $questions = $part['kind'] === 'cue_card'
                ? [['id' => $part['id'], 'text' => "Cue card: {$part['topic']}. ".implode('; ', $part['bullets'] ?? [])], ...$part['followUps']]
                : $part['questions'];
            foreach ($questions as $q) {
                $recording = $byQuestion->get($q['id']);
                if (! $recording) {
                    continue;
                }
                $audio = Storage::disk('local')->get($recording->path);
                $bytes += strlen((string) $audio);
                if ($bytes > self::MAX_AUDIO_BYTES) {
                    throw new RuntimeException('The speaking recordings are too large to send for marking.');
                }
                $ids[] = $q['id'];
                $parts[] = ['text' => "Part {$part['number']}, question id {$q['id']}: {$q['text']}"];
                $parts[] = ['inlineData' => ['mimeType' => $this->mime($recording), 'data' => base64_encode((string) $audio)]];
            }
        }
        if (! $ids) {
            throw new RuntimeException('No recordings to mark.');
        }

        $parts[] = ['text' => <<<'PROMPT'
            Instructions:
            - "transcripts": an exact transcript of each recording, with its question id. Transcribe what was said, including errors; do not correct it.
            - Give a band and a 2-3 sentence comment for each criterion: fluency_coherence, lexical_resource, grammatical_range, pronunciation.
              Base them on the whole test. Quote short examples from the transcripts in the comments.
            - "summary": 2-3 sentences: the overall level and the most useful things to work on.
            PROMPT];

        $keys = ['fluency_coherence', 'lexical_resource', 'grammatical_range', 'pronunciation'];
        $schema = [
            'type' => 'object',
            'properties' => [
                'transcripts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['questionId' => ['type' => 'string', 'enum' => $ids], 'transcript' => ['type' => 'string']],
                        'required' => ['questionId', 'transcript'],
                    ],
                ],
                'criteria' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'enum' => $keys],
                            'band' => ['type' => 'number'],
                            'comment' => ['type' => 'string'],
                        ],
                        'required' => ['key', 'band', 'comment'],
                    ],
                ],
                'summary' => ['type' => 'string'],
            ],
            'required' => ['transcripts', 'criteria', 'summary'],
        ];

        $out = $this->generate($parts, $schema);
        $criteria = $this->criteria($out['criteria'] ?? [], $keys);
        $transcripts = [];
        foreach ($out['transcripts'] ?? [] as $t) {
            if (in_array($t['questionId'] ?? null, $ids, true)) {
                $transcripts[$t['questionId']] = trim((string) $t['transcript']);
            }
        }

        return [
            'band' => Bands::round(array_sum(array_column($criteria, 'band')) / 4),
            'summary' => trim((string) ($out['summary'] ?? '')),
            'criteria' => $criteria,
            'transcripts' => $transcripts,
        ];
    }

    /** One generateContent call with JSON output; returns the decoded object. */
    private function generate(array $parts, array $schema): array
    {
        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->timeout($this->timeout)
            ->acceptJson()
            ->post("{$this->baseUrl}/models/{$this->model}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => self::EXAMINER]]],
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseJsonSchema' => $schema,
                    'temperature' => 0.2,
                ],
            ])
            ->throw();

        $candidate = $response->json('candidates.0');
        if (! $candidate) {
            throw new RuntimeException('Gemini returned no answer: '.($response->json('promptFeedback.blockReason') ?? 'unknown reason'));
        }
        if (($candidate['finishReason'] ?? 'STOP') !== 'STOP') {
            throw new RuntimeException("Gemini stopped early: {$candidate['finishReason']}");
        }
        $text = collect($candidate['content']['parts'] ?? [])->pluck('text')->filter()->implode('');
        $out = json_decode($text, true);
        if (! is_array($out)) {
            throw new RuntimeException('Gemini returned invalid JSON.');
        }

        return $out;
    }

    /**
     * One entry per expected key, in order, bands clamped to 0-9 halves.
     *
     * @param  list<string>  $keys
     */
    private function criteria(array $given, array $keys): array
    {
        $byKey = collect($given)->keyBy('key');
        $criteria = [];
        foreach ($keys as $key) {
            $c = $byKey->get($key);
            if (! $c || ! is_numeric($c['band'] ?? null)) {
                throw new RuntimeException("Gemini did not mark {$key}.");
            }
            $criteria[] = [
                'key' => $key,
                'band' => min(9.0, max(0.0, Bands::round((float) $c['band']))),
                'comment' => trim((string) $c['comment']),
                // AI judges pronunciation least reliably; the app labels it as an estimate
                'indicative' => $key === 'pronunciation',
            ];
        }

        return $criteria;
    }

    /** Turns quoted annotations into character offsets in the text, dropping any quote it cannot find or that overlaps. */
    private function locate(string $text, array $annotations): array
    {
        $found = [];
        foreach ($annotations as $a) {
            $quote = trim((string) ($a['quote'] ?? ''));
            if ($quote === '' || ! in_array($a['category'] ?? null, self::CATEGORIES, true)) {
                continue;
            }
            $start = mb_strpos($text, $quote);
            $start = $start === false ? mb_stripos($text, $quote) : $start;
            if ($start === false) {
                continue;
            }
            $suggestion = trim((string) ($a['suggestion'] ?? ''));
            $found[] = [
                'start' => $start,
                'end' => $start + mb_strlen($quote),
                'category' => $a['category'],
                'message' => trim((string) $a['message']),
                'suggestion' => $suggestion === '' ? null : $suggestion,
            ];
        }

        usort($found, fn ($x, $y) => $x['start'] <=> $y['start']);
        $result = [];
        foreach ($found as $a) {
            $last = end($result);
            if (! $last || $a['start'] >= $last['end']) {
                $result[] = $a;
            }
        }

        return $result;
    }

    private function mime(Recording $recording): string
    {
        return match (strtolower(pathinfo($recording->path, PATHINFO_EXTENSION))) {
            'webm', 'weba' => 'audio/webm',
            'ogg', 'oga', 'opus' => 'audio/ogg',
            'mp3', 'mpga' => 'audio/mp3',
            'm4a', 'mp4' => 'audio/mp4',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            default => 'audio/webm',
        };
    }
}
