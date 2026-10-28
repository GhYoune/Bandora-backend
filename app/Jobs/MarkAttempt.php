<?php

namespace App\Jobs;

use App\Enums\AttemptStatus;
use App\Enums\Skill;
use App\Models\Attempt;
use App\Services\Marking\AiMarker;
use App\Support\Bands;
use App\Support\Text;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** AI-marks a submitted Writing or Speaking attempt. On final failure the credits are refunded. */
class MarkAttempt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public Attempt $attempt) {}

    public function handle(AiMarker $marker): void
    {
        $attempt = $this->attempt->fresh(['test', 'recordings']);
        if ($attempt->status !== AttemptStatus::Marking) {
            return;
        }

        $result = $attempt->test->skill === Skill::Writing
            ? $this->writing($attempt, $marker)
            : $this->speaking($attempt, $marker);

        $attempt->update([
            'result' => $result,
            'band' => $result['band'],
            'status' => AttemptStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function failed(?Throwable $e): void
    {
        DB::transaction(function () {
            $attempt = $this->attempt->fresh();
            if ($attempt->status !== AttemptStatus::Marking) {
                return;
            }
            $attempt->user()->increment('credits', $attempt->credits_charged);
            $attempt->update(['status' => AttemptStatus::MarkingFailed, 'credits_charged' => 0]);
        });
    }

    private function writing(Attempt $attempt, AiMarker $marker): array
    {
        $tasks = [];
        foreach ($attempt->test->content['tasks'] as $task) {
            $text = (string) ($attempt->answers[$task['id']] ?? '');
            $marked = trim($text) === ''
                ? ['band' => 0.0, 'summary' => 'No answer was written for this task.', 'criteria' => [], 'annotations' => [], 'improvedVersion' => null]
                : $marker->markWriting($task, $text);
            $tasks[] = [
                'taskId' => $task['id'],
                'number' => $task['number'],
                'prompt' => $task['prompt'],
                'text' => $text,
                'wordCount' => Text::wordCount($text),
                'minWords' => $task['minWords'],
                ...$marked,
            ];
        }
        $byNumber = collect($tasks)->keyBy('number');

        return [
            'band' => Bands::writing($byNumber->get(1)['band'] ?? null, $byNumber->get(2)['band'] ?? null),
            'tasks' => $tasks,
        ];
    }

    private function speaking(Attempt $attempt, AiMarker $marker): array
    {
        $content = $attempt->test->content;
        $marked = $marker->markSpeaking($content, $attempt->recordings);
        $recordings = $attempt->recordings->keyBy('question_id');

        $parts = [];
        foreach ($content['parts'] as $part) {
            $questions = $part['kind'] === 'cue_card'
                ? [['id' => $part['id'], 'text' => $part['topic']], ...$part['followUps']]
                : $part['questions'];
            $items = [];
            foreach ($questions as $q) {
                if ($recording = $recordings->get($q['id'])) {
                    $items[] = [
                        'questionId' => $q['id'],
                        'question' => $q['text'],
                        'recordingId' => $recording->id,
                        'transcript' => $marked['transcripts'][$q['id']] ?? null,
                        'durationSeconds' => $recording->duration_seconds,
                    ];
                }
            }
            if ($items) {
                $parts[] = ['number' => $part['number'], 'items' => $items];
            }
        }

        return [
            'band' => $marked['band'],
            'summary' => $marked['summary'],
            'criteria' => $marked['criteria'],
            'parts' => $parts,
        ];
    }
}
