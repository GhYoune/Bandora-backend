<?php

namespace Tests\Feature;

use App\Models\Recording;
use App\Services\Marking\AiMarker;
use App\Services\Marking\GeminiAiMarker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GeminiMarkerTest extends TestCase
{
    private const TASK = [
        'id' => 'w2', 'number' => 2, 'kind' => 'essay', 'minWords' => 250, 'suggestedMinutes' => 40,
        'prompt' => 'Some people think cities are better places to live. Discuss.', 'bullets' => [], 'closing' => null,
    ];

    private function marker(): GeminiAiMarker
    {
        return new GeminiAiMarker('test-key', 'gemini-3.8-flash');
    }

    private function reply(array $json, string $finish = 'STOP'): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]], 'finishReason' => $finish]]];
    }

    public function test_writing_is_marked_and_quotes_become_offsets(): void
    {
        $text = "Nowadays alot of people move to cities. I think it is good.\n\nCities have many jobs.";
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->reply([
            'summary' => 'A clear answer at Band 6.',
            'criteria' => [
                ['key' => 'task_response', 'band' => 6.2, 'comment' => 'Develop your ideas.'],
                ['key' => 'coherence_cohesion', 'band' => 6, 'comment' => 'Good paragraphs.'],
                ['key' => 'lexical_resource', 'band' => 5.5, 'comment' => 'Repetitive.'],
                ['key' => 'grammatical_range', 'band' => 6.5, 'comment' => 'Some complex sentences.'],
            ],
            'annotations' => [
                ['quote' => 'good', 'category' => 'vocabulary', 'message' => 'Too common.', 'suggestion' => 'beneficial'],
                ['quote' => 'alot', 'category' => 'spelling', 'message' => 'Spelling.', 'suggestion' => 'a lot'],
                ['quote' => 'not in the text', 'category' => 'grammar', 'message' => 'Dropped.', 'suggestion' => ''],
                ['quote' => 'alot of', 'category' => 'grammar', 'message' => 'Overlaps, dropped.', 'suggestion' => ''],
            ],
            'improvedVersion' => 'Nowadays a lot of people move to cities.',
        ]))]);

        $result = $this->marker()->markWriting(self::TASK, $text);

        $this->assertSame(6.0, $result['band']);
        $this->assertSame(['task_response', 'coherence_cohesion', 'lexical_resource', 'grammatical_range'], array_column($result['criteria'], 'key'));
        $this->assertSame(6.0, $result['criteria'][0]['band'], 'bands are rounded to halves');
        $this->assertCount(2, $result['annotations']);
        [$spelling, $vocab] = $result['annotations'];
        $this->assertSame('alot', mb_substr($text, $spelling['start'], $spelling['end'] - $spelling['start']));
        $this->assertSame('good', mb_substr($text, $vocab['start'], $vocab['end'] - $vocab['start']));
        $this->assertSame('beneficial', $vocab['suggestion']);
        $this->assertSame('Nowadays a lot of people move to cities.', $result['improvedVersion']);

        Http::assertSent(function (Request $request) use ($text) {
            return str_contains($request->url(), 'models/gemini-3.8-flash:generateContent')
                && $request->header('x-goog-api-key')[0] === 'test-key'
                && $request['generationConfig']['responseMimeType'] === 'application/json'
                && str_contains($request['contents'][0]['parts'][0]['text'], $text);
        });
    }

    public function test_speaking_sends_the_audio_and_returns_transcripts(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('recordings/a/one.webm', 'AUDIO-ONE');
        Storage::disk('local')->put('recordings/a/two.webm', 'AUDIO-TWO');
        $recordings = collect([
            new Recording(['question_id' => 'q1', 'path' => 'recordings/a/one.webm', 'duration_seconds' => 20]),
            new Recording(['question_id' => 'cue', 'path' => 'recordings/a/two.webm', 'duration_seconds' => 110]),
        ]);
        $content = ['skill' => 'speaking', 'parts' => [
            ['number' => 1, 'kind' => 'interview', 'topic' => 'Home', 'questions' => [['id' => 'q1', 'text' => 'Where do you live?']]],
            ['number' => 2, 'kind' => 'cue_card', 'id' => 'cue', 'topic' => 'Describe a trip', 'bullets' => ['where'], 'closing' => '', 'followUps' => []],
        ]];
        Http::fake(['*' => Http::response($this->reply([
            'transcripts' => [['questionId' => 'q1', 'transcript' => 'I live in Algiers.'], ['questionId' => 'cue', 'transcript' => 'Last year I went...']],
            'criteria' => [
                ['key' => 'fluency_coherence', 'band' => 6, 'comment' => 'a'],
                ['key' => 'lexical_resource', 'band' => 6.5, 'comment' => 'b'],
                ['key' => 'grammatical_range', 'band' => 6, 'comment' => 'c'],
                ['key' => 'pronunciation', 'band' => 7, 'comment' => 'd'],
            ],
            'summary' => 'Good.',
        ]))]);

        $result = $this->marker()->markSpeaking($content, $recordings);

        $this->assertSame(6.5, $result['band']);
        $this->assertSame(['q1' => 'I live in Algiers.', 'cue' => 'Last year I went...'], $result['transcripts']);
        $this->assertTrue(collect($result['criteria'])->firstWhere('key', 'pronunciation')['indicative']);
        Http::assertSent(function (Request $request) {
            $audio = collect($request['contents'][0]['parts'])->pluck('inlineData')->filter()->values();

            return $audio->count() === 2
                && $audio[0]['mimeType'] === 'audio/webm'
                && base64_decode($audio[1]['data']) === 'AUDIO-TWO';
        });
    }

    public function test_an_incomplete_answer_throws_so_the_job_retries(): void
    {
        Http::fake(['*' => Http::response($this->reply(['summary' => 'x'], 'MAX_TOKENS'))]);
        $this->expectException(RuntimeException::class);
        $this->marker()->markWriting(self::TASK, 'Some text here.');
    }

    public function test_a_missing_criterion_throws(): void
    {
        Http::fake(['*' => Http::response($this->reply([
            'summary' => 'x', 'annotations' => [], 'improvedVersion' => '',
            'criteria' => [['key' => 'task_response', 'band' => 6, 'comment' => 'a']],
        ]))]);
        $this->expectException(RuntimeException::class);
        $this->marker()->markWriting(self::TASK, 'Some text here.');
    }

    public function test_driver_is_selected_from_config(): void
    {
        config(['bandora.marking.driver' => 'gemini', 'bandora.marking.gemini.key' => 'k']);
        $this->assertInstanceOf(GeminiAiMarker::class, app(AiMarker::class));
    }
}
