<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Enums\Skill;
use App\Models\Attempt;
use App\Models\User;
use App\Services\Marking\ObjectiveMarker;
use Illuminate\Support\Collection;

/** "My weak points": patterns across every submitted attempt, custom and practice included. */
class InsightsService
{
    /** AI-marked attempts used per skill. */
    private const AI_WINDOW = 10;

    /** Listening/Reading attempts re-marked to build the question-type picture. */
    private const OBJECTIVE_WINDOW = 30;

    public function __construct(private ObjectiveMarker $marker) {}

    public function build(User $user): array
    {
        $completed = $user->attempts()->with('test')
            ->where('status', AttemptStatus::Completed)
            ->orderByDesc('completed_at')->orderByDesc('id') // ULIDs break same-second ties
            ->get();
        $bySkill = $completed->groupBy(fn (Attempt $a) => $a->test->skill->value);

        return [
            ...$this->objective($completed->filter(fn (Attempt $a) => $a->test->skill->isObjective())->take(self::OBJECTIVE_WINDOW)),
            'writing' => $this->ai($bySkill->get(Skill::Writing->value, collect())->take(self::AI_WINDOW), writing: true),
            'speaking' => $this->ai($bySkill->get(Skill::Speaking->value, collect())->take(self::AI_WINDOW), writing: false),
        ];
    }

    /** @param  Collection<int, Attempt>  $attempts  newest first */
    private function objective(Collection $attempts): array
    {
        $types = [];
        $words = [];
        foreach ($attempts as $attempt) {
            $marked = $this->marker->mark($attempt->test->content, $attempt->test->answer_key, $attempt->answers ?? []);
            foreach ($marked['breakdown'] as $row) {
                $types[$row['type']] ??= ['type' => $row['type'], 'correct' => 0, 'total' => 0];
                $types[$row['type']]['correct'] += $row['correct'];
                $types[$row['type']]['total'] += $row['total'];
            }
            foreach ($marked['misspelledWords'] as $w) {
                $key = mb_strtolower($w['correctAnswer']);
                // Attempts come newest first, so the first misspelling seen is the most recent.
                $words[$key] ??= ['correctAnswer' => $w['correctAnswer'], 'userAnswer' => $w['userAnswer'], 'count' => 0];
                $words[$key]['count']++;
            }
        }

        $types = collect($types)->sortBy(fn ($t) => [$t['correct'] / max(1, $t['total']), -$t['total']])->values()->all();
        $words = collect($words)->sortByDesc('count')->take(20)->values()->all();

        return ['questionTypes' => $types, 'misspelledWords' => $words];
    }

    /** @param  Collection<int, Attempt>  $attempts */
    private function ai(Collection $attempts, bool $writing): array
    {
        $bands = [];
        $categories = [];
        foreach ($attempts as $attempt) {
            $result = $attempt->result ?? [];
            $sets = $writing ? array_column($result['tasks'] ?? [], 'criteria') : [$result['criteria'] ?? []];
            foreach ($sets as $criteria) {
                foreach ($criteria as $c) {
                    $bands[$c['key']][] = (float) $c['band'];
                }
            }
            if ($writing) {
                foreach ($result['tasks'] ?? [] as $task) {
                    foreach ($task['annotations'] ?? [] as $a) {
                        $categories[$a['category']] = ($categories[$a['category']] ?? 0) + 1;
                    }
                }
            }
        }

        return [
            'attemptsCounted' => $attempts->count(),
            'criteria' => collect($bands)
                ->map(fn (array $b, string $key) => ['key' => $key, 'averageBand' => round(array_sum($b) / count($b), 1)])
                ->sortBy('averageBand')->values()->all(),
            'errorCategories' => collect($categories)
                ->map(fn (int $n, string $category) => ['category' => $category, 'count' => $n])
                ->sortByDesc('count')->values()->all(),
        ];
    }
}
