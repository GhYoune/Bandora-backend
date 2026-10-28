<?php

namespace App\Services\Marking;

use App\Support\Bands;
use App\Support\Text;
use Illuminate\Support\Collection;

/**
 * Rule-based stand-in for AI marking (MARKING_DRIVER=fake). Produces plausible feedback in the
 * contract's shape so the whole flow works before an AI provider is chosen. Not a real assessment.
 */
class FakeAiMarker implements AiMarker
{
    private const VOCAB = [
        'very' => 'extremely / highly',
        'good' => 'beneficial / valuable',
        'bad' => 'harmful / damaging',
        'big' => 'significant / substantial',
        'nice' => 'pleasant / welcoming',
        'thing' => 'aspect / factor',
        'things' => 'aspects / factors',
        'a lot of' => 'a great deal of / many',
        'get' => 'obtain / receive',
    ];

    private const SPELLING = [
        'alot' => 'a lot', 'becouse' => 'because', 'recieve' => 'receive', 'goverment' => 'government',
        'enviroment' => 'environment', 'wich' => 'which', 'untill' => 'until', 'beleive' => 'believe',
        'oportunity' => 'opportunity', 'definately' => 'definitely',
    ];

    private const SAMPLE_TRANSCRIPTS = [
        "Well, I'm from a medium-sized city on the coast. It's quite busy in the summer because a lot of tourists come, but in winter it's much calmer.",
        'I think the thing I like most is the food, honestly. There are small restaurants everywhere and you can eat fresh fish every day.',
        'Yes, it has changed a lot. When I was a child there were many empty fields near my house, but now there are new apartment buildings.',
        "Maybe, yes. I would like to work abroad for a few years first, but in the long term I think I'd come back because my family is there.",
    ];

    public function markWriting(array $task, string $text): array
    {
        $wordCount = Text::wordCount($text);
        preg_match_all("/[a-z']+/", strtolower($text), $m);
        $words = $m[0];
        $variety = $words ? count(array_unique($words)) / count($words) : 0;
        $sentences = max(1, count(array_filter(preg_split('/[.!?]+/', $text), 'trim')));
        $paragraphs = count(array_filter(preg_split('/\n\s*\n/', $text), 'trim'));
        $formal = $task['kind'] === 'essay' || preg_match('/dear (sir|madam)/i', $text);
        $annotations = $this->annotate($text, (bool) $formal);
        $min = $task['minWords'];

        $base = match (true) {
            $wordCount < $min * 0.6 => 4.5,
            $wordCount < $min => 5.5,
            $variety > 0.55 => 7.0,
            $variety > 0.45 => 6.5,
            default => 6.0,
        };
        $penalty = min(1, count($annotations) * 0.1);
        $enoughParagraphs = $paragraphs >= ($task['number'] === 1 ? 3 : 4);
        $complex = count($words) / $sentences > 14;

        $criteria = [
            [
                'key' => $task['number'] === 1 ? 'task_achievement' : 'task_response',
                'band' => $this->clamp($base - ($wordCount < $min ? 0.5 : 0)),
                'indicative' => false,
                'comment' => $wordCount < $min
                    ? "Your answer has {$wordCount} words, below the {$min}-word minimum, so this criterion is capped. Cover every part of the question fully."
                    : ($task['number'] === 1
                        ? 'You address the purpose of the letter. Develop each bullet point with a specific detail and keep the tone right for the reader.'
                        : 'Your position is clear. Support each main idea with a concrete example and discuss both views before you conclude.'),
            ],
            [
                'key' => 'coherence_cohesion',
                'band' => $this->clamp($base - ($enoughParagraphs ? 0 : 0.5)),
                'indicative' => false,
                'comment' => $enoughParagraphs
                    ? 'Paragraphing is logical. Use a wider range of linking words and avoid starting several sentences with the same connector.'
                    : 'Organise your answer into clear paragraphs: an introduction, one paragraph per main idea, and a conclusion.',
            ],
            [
                'key' => 'lexical_resource',
                'band' => $this->clamp($base + ($variety > 0.5 ? 0.5 : 0) - $penalty / 2),
                'indicative' => false,
                'comment' => 'Some good topic vocabulary. Replace very common words (good, very, thing) with more precise alternatives, as highlighted.',
            ],
            [
                'key' => 'grammatical_range',
                'band' => $this->clamp($base - $penalty + ($complex ? 0.5 : 0)),
                'indicative' => false,
                'comment' => $complex
                    ? 'You use a mix of simple and complex sentences. Check the highlighted errors, which are mostly avoidable.'
                    : 'Most sentences are short and simple. Add relative clauses and conditionals to show grammatical range.',
            ],
        ];
        $band = $this->clamp(array_sum(array_column($criteria, 'band')) / 4);

        return [
            'band' => $band,
            'summary' => match (true) {
                $band >= 7 => 'A strong answer. A few precise vocabulary choices and fewer small errors would move you towards Band 8.',
                $band >= 6 => 'A solid answer that communicates clearly. The fastest gains are in vocabulary precision and fixing repeated small errors.',
                default => 'Your answer needs more development. Focus on reaching the word count and covering every part of the task.',
            },
            'criteria' => $criteria,
            'annotations' => $annotations,
            'improvedVersion' => $wordCount ? $this->applySuggestions($text, $annotations) : null,
        ];
    }

    public function markSpeaking(array $content, Collection $recordings): array
    {
        $total = $recordings->sum('duration_seconds');
        $base = $total > 300 ? 6.5 : ($total > 120 ? 6.0 : 5.0);
        $criteria = [
            ['key' => 'fluency_coherence', 'band' => $this->clamp($base), 'indicative' => false, 'comment' => 'You speak at length with only occasional hesitation. Use discourse markers (actually, as a result, on the other hand) to link ideas.'],
            ['key' => 'lexical_resource', 'band' => $this->clamp($base + 0.5), 'indicative' => false, 'comment' => 'Good range of everyday vocabulary. Add a few less common phrases and idioms to reach a higher band.'],
            ['key' => 'grammatical_range', 'band' => $this->clamp($base - 0.5), 'indicative' => false, 'comment' => 'Mostly accurate simple structures. Practise past and conditional forms when describing experiences.'],
            ['key' => 'pronunciation', 'band' => $this->clamp($base), 'indicative' => true, 'comment' => 'Generally clear. This score is an estimate: AI is less reliable at judging pronunciation than the other criteria.'],
        ];

        $transcripts = [];
        foreach ($recordings->values() as $i => $recording) {
            $transcripts[$recording->question_id] = self::SAMPLE_TRANSCRIPTS[$i % count(self::SAMPLE_TRANSCRIPTS)];
        }

        return [
            'band' => $this->clamp(array_sum(array_column($criteria, 'band')) / 4),
            'summary' => 'You communicate clearly and keep talking without long pauses. To improve, extend answers with reasons and examples, and use more precise vocabulary. (Development marking: transcripts are sample text.)',
            'criteria' => $criteria,
            'transcripts' => $transcripts,
        ];
    }

    private function clamp(float $band): float
    {
        return min(9, max(3, Bands::round($band)));
    }

    /** @return list<array{start: int, end: int, category: string, message: string, suggestion: ?string}> */
    private function annotate(string $text, bool $formal): array
    {
        $found = [];
        $add = function (string $pattern, callable $make) use ($text, &$found) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $i => [$match, $offset]) {
                    // offsets must be in characters for the frontend, not bytes
                    $start = mb_strlen(substr($text, 0, $offset));
                    $found[] = ['start' => $start, 'end' => $start + mb_strlen($match), ...$make($match, $matches[1][$i][0] ?? null)];
                }
            }
        };

        $add('/\bi\b/', fn () => ['category' => 'grammar', 'message' => "The pronoun 'I' is always written with a capital letter.", 'suggestion' => 'I']);
        $add('/\b(\w+) \1\b/i', fn ($m, $word) => ['category' => 'grammar', 'message' => "'{$word}' is repeated.", 'suggestion' => $word]);
        foreach (self::SPELLING as $wrong => $right) {
            $add("/\\b{$wrong}\\b/i", fn () => ['category' => 'spelling', 'message' => "Spelling: '{$right}'.", 'suggestion' => $right]);
        }
        foreach (self::VOCAB as $word => $better) {
            $add("/\\b{$word}\\b/i", fn () => [
                'category' => 'vocabulary',
                'message' => "'{$word}' is very common. A more precise word would raise your Lexical Resource score.",
                'suggestion' => $better,
            ]);
        }
        $add('/[.!?] +[a-z]/', fn () => ['category' => 'punctuation', 'message' => 'Start a new sentence with a capital letter.', 'suggestion' => null]);
        if ($formal) {
            $add("/\\b\\w+n't\\b|\\b(?:it|that|there|I)'s\\b|\\bI'm\\b/i", fn ($m) => [
                'category' => 'style',
                'message' => "Avoid contractions like '{$m}' in formal writing.",
                'suggestion' => null,
            ]);
        }

        usort($found, fn ($a, $b) => $a['start'] <=> $b['start']);
        $result = [];
        foreach ($found as $a) {
            $last = end($result);
            if (! $last || $a['start'] >= $last['end']) {
                $result[] = $a;
            }
        }

        return array_slice($result, 0, 14);
    }

    private function applySuggestions(string $text, array $annotations): string
    {
        foreach (array_reverse($annotations) as $a) {
            if ($a['suggestion'] === null) {
                continue;
            }
            $replacement = explode(' / ', $a['suggestion'])[0];
            $text = mb_substr($text, 0, $a['start']).$replacement.mb_substr($text, $a['end']);
        }

        return $text;
    }
}
