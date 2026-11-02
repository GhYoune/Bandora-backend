<?php

namespace App\Services;

use App\Enums\Skill;
use App\Exceptions\BusinessException;
use App\Models\Attempt;
use App\Models\Test;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Custom practice: the student brings a task from outside (a Writing prompt, or the questions of one
 * Speaking part). It becomes a private test owned by the student, so the normal attempt flow, AI
 * marking and results all apply. Custom attempts never count toward the estimated band.
 */
class CustomPracticeService
{
    public function __construct(private AttemptService $attempts) {}

    public function start(User $user, array $data): Attempt
    {
        if ($user->availableCredits() < 1) {
            throw new BusinessException('no_credits', 'Custom practice is marked by AI and needs a credit. You have none left.');
        }

        return DB::transaction(function () use ($user, $data) {
            $test = $data['skill'] === 'writing' ? $this->writingTest($data) : $this->speakingTest($data);
            $test->fill(['user_id' => $user->id, 'is_free' => true, 'is_published' => false])->save();

            return $this->attempts->start($user, $test)[0];
        });
    }

    private function writingTest(array $data): Test
    {
        $number = (int) $data['taskNumber'];

        return new Test([
            'skill' => Skill::Writing,
            'title' => "Your own task · Writing Task {$number}",
            'description' => $this->snippet($data['prompt']),
            'duration_seconds' => $data['timed'] ? ($number === 1 ? 20 : 40) * 60 : 0,
            'form' => ['tasks' => [['number' => $number, 'prompt' => $data['prompt']]]],
        ]);
    }

    private function speakingTest(array $data): Test
    {
        $part = (int) $data['part'];
        $questions = fn () => array_map(fn (string $q) => ['text' => $q], array_values(array_filter(array_map('trim', $data['questions'] ?? []))));

        $form = match ($part) {
            1 => ['part1' => ['topic' => 'Your questions', 'questions' => $questions()]],
            3 => ['part3' => ['topic' => 'Your questions', 'questions' => $questions()]],
            2 => ['part2' => [
                'topic' => trim($data['cueCard']['topic']),
                'bullets' => $data['cueCard']['bullets'] ?? [],
                'closing' => $data['cueCard']['closing'] ?? '',
                'follow_ups' => [],
            ]],
        };

        return new Test([
            'skill' => Skill::Speaking,
            'title' => "Your own questions · Speaking Part {$part}",
            'description' => $this->snippet($part === 2 ? $data['cueCard']['topic'] : implode(' ', $data['questions'])),
            // Shown on the attempt only; Speaking is paced per question.
            'duration_seconds' => ($part === 2 ? 4 : 5) * 60,
            'form' => $form,
        ]);
    }

    private function snippet(string $text): string
    {
        return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $text)), 0, 120, '…');
    }
}
