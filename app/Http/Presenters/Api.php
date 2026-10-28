<?php

namespace App\Http\Presenters;

use App\Enums\AttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\Skill;
use App\Models\Attempt;
use App\Models\Pack;
use App\Models\PaymentRequest;
use App\Models\Recording;
use App\Models\Test;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\Marking\ObjectiveMarker;
use App\Support\Bands;
use Illuminate\Support\Collection;

/**
 * Builds the JSON shapes of contract/openapi.yaml (one method per schema).
 * Keep in sync with the contract and with frontend/src/mocks/handlers.ts.
 */
class Api
{
    public function __construct(private AttemptService $attempts, private ObjectiveMarker $marker) {}

    public function me(User $user): array
    {
        $pending = $user->paymentRequests()->where('status', PaymentStatus::Pending)->latest()->first();

        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'emailVerified' => $user->hasVerifiedEmail(),
            'whatsapp' => $user->whatsapp,
            'access' => [
                'tier' => $user->tier(),
                'pack' => $user->pack_expires_at ? [
                    'packId' => $user->pack_id,
                    'name' => $user->pack_name,
                    'startsAt' => $user->pack_started_at?->toIso8601String(),
                    'expiresAt' => $user->pack_expires_at->toIso8601String(),
                ] : null,
                'credits' => $user->availableCredits(),
            ],
            'pendingPayment' => $pending ? $this->payment($pending) : null,
        ];
    }

    public function pack(Pack $pack): array
    {
        return [
            'id' => $pack->id,
            'name' => $pack->name,
            'durationDays' => $pack->duration_days,
            'priceDzd' => $pack->price_dzd,
            'credits' => $pack->credits,
            'highlighted' => $pack->highlighted,
            'features' => $pack->features,
        ];
    }

    public function payment(PaymentRequest $payment): array
    {
        return [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'packId' => $payment->pack_id,
            'packName' => $payment->pack_name,
            'amountDzd' => $payment->amount_dzd,
            'status' => $payment->status->value,
            'createdAt' => $payment->created_at->toIso8601String(),
            'whatsappUrl' => $payment->whatsappUrl(),
        ];
    }

    /** @param  Collection<int, Attempt>  $mine  the student's attempts for this test */
    public function testSummary(Test $test, User $user, Collection $mine): array
    {
        $done = $mine->where('status', AttemptStatus::Completed)->sortByDesc('completed_at')->first();

        return [
            'id' => $test->id,
            'skill' => $test->skill->value,
            'title' => $test->title,
            'description' => $test->description,
            'durationSeconds' => $test->duration_seconds,
            'questionCount' => $test->question_count,
            ...($test->skill === Skill::Writing ? ['writingTasks' => $test->writingTasks()] : []),
            ...($test->skill->isObjective() ? [] : ['creditCost' => $test->creditCost()]),
            'access' => $this->attempts->access($test, $user),
            'attemptsCount' => $mine->where('status', '!=', AttemptStatus::InProgress)->count(),
            'inProgressAttemptId' => $mine->firstWhere('status', AttemptStatus::InProgress)?->id,
            'lastResult' => $done ? [
                'attemptId' => $done->id,
                'band' => $done->band,
                'completedAt' => $done->completed_at->toIso8601String(),
            ] : null,
        ];
    }

    public function attemptSummary(Attempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'testId' => $attempt->test_id,
            'skill' => $attempt->test->skill->value,
            'title' => $attempt->test->title,
            'status' => $attempt->status->value,
            'band' => $attempt->band,
            'rawScore' => $attempt->raw_score,
            'practiceConditions' => $attempt->practiceConditions(),
            'startedAt' => $attempt->started_at->toIso8601String(),
            'completedAt' => $attempt->completed_at?->toIso8601String(),
            'remainingSeconds' => $attempt->remaining_seconds,
            'progress' => $this->attempts->progress($attempt),
            'position' => $attempt->position ?: ['section' => 0],
        ];
    }

    /** The full attempt for the exam screen: content without answers, plus saved progress. */
    public function attempt(Attempt $attempt): array
    {
        return [
            ...$this->attemptSummary($attempt),
            'listeningPlays' => $attempt->listening_plays,
            'answers' => (object) ($attempt->answers ?? []),
            'flagged' => $attempt->flagged ?? [],
            'recordings' => $attempt->recordings->map(fn (Recording $r) => $this->recording($r))->values()->all(),
            'content' => $attempt->test->content,
        ];
    }

    public function recording(Recording $recording): array
    {
        return [
            'id' => $recording->id,
            'questionId' => $recording->question_id,
            'durationSeconds' => $recording->duration_seconds,
            'url' => $recording->url(),
        ];
    }

    public function result(Attempt $attempt): array
    {
        $test = $attempt->test;
        $base = [
            'attemptId' => $attempt->id,
            'testId' => $test->id,
            'title' => $test->title,
            'completedAt' => $attempt->completed_at?->toIso8601String(),
        ];

        if ($test->skill->isObjective()) {
            $marked = $this->marker->mark($test->content, $test->answer_key, $attempt->answers ?? []);

            return [
                ...$base,
                'skill' => $test->skill->value,
                'status' => 'completed',
                ...$marked,
                'band' => $attempt->band ?? Bands::fromRawScore($test->skill, $marked['rawScore']),
                'practiceConditions' => $attempt->practiceConditions(),
            ];
        }

        $status = match ($attempt->status) {
            AttemptStatus::Completed => 'completed',
            AttemptStatus::MarkingFailed => 'marking_failed',
            default => 'marking',
        };
        $result = $attempt->result ?? [];

        if ($test->skill === Skill::Writing) {
            return [...$base, 'skill' => 'writing', 'status' => $status, 'band' => $attempt->band, 'tasks' => $result['tasks'] ?? []];
        }

        // Recording links are signed per request, so they are built here rather than stored.
        $recordings = $attempt->recordings->keyBy('id');
        $parts = collect($result['parts'] ?? [])->map(fn (array $part) => [
            'number' => $part['number'],
            'items' => collect($part['items'])->map(fn (array $item) => [
                'questionId' => $item['questionId'],
                'question' => $item['question'],
                'audioUrl' => $recordings->get($item['recordingId'])?->url(),
                'transcript' => $item['transcript'],
                'durationSeconds' => $item['durationSeconds'],
            ])->all(),
        ])->all();

        return [
            ...$base,
            'skill' => 'speaking',
            'status' => $status,
            'band' => $attempt->band,
            'summary' => $result['summary'] ?? null,
            'criteria' => $result['criteria'] ?? [],
            'parts' => $parts,
        ];
    }
}
