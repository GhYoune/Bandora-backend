<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Enums\Skill;
use App\Http\Presenters\Api;
use App\Models\Attempt;
use App\Models\User;
use App\Support\Bands;

class DashboardService
{
    public function __construct(private Api $present) {}

    /**
     * Estimated band per skill = average of the last 3 completed attempts (practice-condition
     * Listening excluded). Overall = average of the 4 skills, only once all 4 have a band.
     */
    public function build(User $user): array
    {
        $attempts = $user->attempts()->with('test')->withCount('recordings')->get();
        $counted = $attempts
            ->filter(fn (Attempt $a) => $a->status === AttemptStatus::Completed && $a->band !== null && ! $a->practiceConditions())
            ->sortBy('completed_at')
            ->values();

        $skills = collect(Skill::cases())->map(function (Skill $skill) use ($counted) {
            $last3 = $counted->filter(fn (Attempt $a) => $a->test->skill === $skill)->take(-3);

            return [
                'skill' => $skill->value,
                'band' => $last3->isEmpty() ? null : Bands::round($last3->avg('band')),
                'testsCounted' => $last3->count(),
            ];
        });
        $missing = $skills->whereNull('band')->pluck('skill')->values();

        $continue = $attempts->where('status', AttemptStatus::InProgress)->sortByDesc('updated_at')->first();

        return [
            'skills' => $skills->all(),
            'overallBand' => $missing->isEmpty() ? Bands::round($skills->avg('band')) : null,
            'missingSkills' => $missing->all(),
            'trend' => $counted->take(-30)->map(fn (Attempt $a) => [
                'attemptId' => $a->id,
                'skill' => $a->test->skill->value,
                'band' => $a->band,
                'completedAt' => $a->completed_at->toIso8601String(),
            ])->values()->all(),
            'continueAttempt' => $continue ? $this->present->attemptSummary($continue) : null,
            'recentAttempts' => $attempts
                ->where('status', '!=', AttemptStatus::InProgress)
                ->sortByDesc(fn (Attempt $a) => $a->completed_at ?? $a->updated_at)
                ->take(5)
                ->map(fn (Attempt $a) => $this->present->attemptSummary($a))
                ->values()->all(),
        ];
    }
}
