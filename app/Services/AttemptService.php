<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Enums\Skill;
use App\Exceptions\BusinessException;
use App\Jobs\MarkAttempt;
use App\Models\Attempt;
use App\Models\Test;
use App\Models\User;
use App\Services\Marking\ObjectiveMarker;
use App\Support\Bands;
use Illuminate\Support\Facades\DB;

class AttemptService
{
    public function __construct(private ObjectiveMarker $marker) {}

    /** free = free-tier test, included = unlocked by the pack, locked = needs a pack. */
    public function access(Test $test, User $user): string
    {
        if ($test->is_free) {
            return 'free';
        }

        return $user->hasActivePack() ? 'included' : 'locked';
    }

    /** @return array{0: Attempt, 1: bool} the attempt and whether it was created now */
    public function start(User $user, Test $test, int $listeningPlays = 1): array
    {
        $open = $user->attempts()->where('test_id', $test->id)->where('status', AttemptStatus::InProgress)->first();
        if ($open) {
            return [$open, false];
        }
        if ($this->access($test, $user) === 'locked') {
            throw new BusinessException('pack_required', 'Choose a pack to unlock this test.');
        }
        if (! $test->skill->isObjective() && $user->availableCredits() < 1) {
            throw new BusinessException('no_credits', 'You have no AI marking credits left.');
        }

        $attempt = $user->attempts()->create([
            'test_id' => $test->id,
            'status' => AttemptStatus::InProgress,
            'listening_plays' => $test->skill === Skill::Listening && $listeningPlays === 2 ? 2 : 1,
            'answers' => [],
            'flagged' => [],
            'remaining_seconds' => $test->duration_seconds,
            'position' => ['section' => 0],
            'started_at' => now(),
        ]);

        return [$attempt, true];
    }

    public function save(Attempt $attempt, array $progress): void
    {
        $this->ensureOpen($attempt);
        $attempt->update(array_filter([
            'answers' => $progress['answers'] ?? null,
            'flagged' => $progress['flagged'] ?? null,
            'remaining_seconds' => $progress['remainingSeconds'] ?? null,
            'position' => $progress['position'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Listening/Reading are marked immediately. Writing/Speaking charge credits and are marked
     * by AI in the background; the result endpoint reports `marking` until the job finishes.
     */
    public function submit(Attempt $attempt, array $progress): Attempt
    {
        // Saved first and outside the transaction: a refusal (no credits) must not lose the answers.
        $this->save($attempt, $progress);

        return DB::transaction(function () use ($attempt) {
            $attempt = Attempt::whereKey($attempt->id)->lockForUpdate()->with('test')->first();
            $this->ensureOpen($attempt);
            $test = $attempt->test;

            if ($test->skill->isObjective()) {
                $marked = $this->marker->mark($test->content, $test->answer_key, $attempt->answers);
                $attempt->update([
                    'raw_score' => $marked['rawScore'],
                    'band' => Bands::fromRawScore($test->skill, $marked['rawScore']),
                    'status' => AttemptStatus::Completed,
                    'completed_at' => now(),
                ]);

                return $attempt;
            }

            $cost = $this->creditCost($attempt);
            $user = $attempt->user()->lockForUpdate()->first();
            if ($user->availableCredits() < $cost) {
                throw new BusinessException('no_credits', "Marking this test needs {$cost} credits and you have {$user->availableCredits()}.");
            }
            $user->decrement('credits', $cost);
            $attempt->update(['status' => AttemptStatus::Marking, 'credits_charged' => $cost]);
            MarkAttempt::dispatch($attempt)->afterCommit();

            return $attempt;
        });
    }

    /** Writing: one credit per task. Speaking: one per part with at least one answer. */
    public function creditCost(Attempt $attempt): int
    {
        $content = $attempt->test->content;
        if ($attempt->test->skill === Skill::Writing) {
            return count($content['tasks']);
        }
        $answered = $attempt->recordings()->pluck('question_id')->all();
        $parts = collect($content['parts'])->filter(function (array $part) use ($answered) {
            $ids = $part['kind'] === 'cue_card'
                ? [$part['id'], ...array_column($part['followUps'], 'id')]
                : array_column($part['questions'], 'id');

            return array_intersect($ids, $answered) !== [];
        });

        return max(1, $parts->count());
    }

    /** @return array{answered: int, total: int} */
    public function progress(Attempt $attempt): array
    {
        $test = $attempt->test;
        $content = $test->content;

        return match ($test->skill) {
            Skill::Listening, Skill::Reading => [
                'answered' => $this->marker->countAnswered($content, $attempt->answers ?? []),
                'total' => $test->question_count,
            ],
            Skill::Writing => [
                'answered' => collect($content['tasks'])->filter(fn ($t) => trim((string) ($attempt->answers[$t['id']] ?? '')) !== '')->count(),
                'total' => $test->question_count,
            ],
            Skill::Speaking => [
                'answered' => $attempt->recordings_count ?? $attempt->recordings()->count(),
                'total' => $test->question_count,
            ],
        };
    }

    public function ensureOpen(Attempt $attempt): void
    {
        if (! $attempt->isOpen()) {
            throw new BusinessException('attempt_closed', 'This test has already been submitted.', 409);
        }
    }
}
