<?php

namespace App\Services\Marking;

/**
 * Marks Listening and Reading against the answer key (one mark per question number).
 * Mirrors frontend/src/mocks/scoring.ts so the prototype and the real API agree.
 */
class ObjectiveMarker
{
    /**
     * @return array{rawScore: int, maxScore: int, review: list<array>, breakdown: list<array>}
     */
    public function mark(array $content, array $key, array $answers): array
    {
        $review = [];
        foreach ($this->gradables($content) as $g) {
            $accepted = $key[$g['questionId']] ?? [];
            $given = $answers[$g['questionId']] ?? null;

            if ($g['select'] > 1) {
                $chosen = array_map([$this, 'normalise'], is_array($given) ? $given : ($given ? [$given] : []));
                $right = array_values(array_filter($chosen, fn ($c) => in_array($c, $accepted, true)));
                $wrong = array_values(array_filter($chosen, fn ($c) => ! in_array($c, $accepted, true)));
                $missing = array_values(array_filter($accepted, fn ($a) => ! in_array($a, $right, true)));
                foreach ($g['numbers'] as $i => $number) {
                    $isCorrect = $i < count($right);
                    $review[] = [
                        'number' => $number,
                        'questionId' => $g['questionId'],
                        'type' => $g['type'],
                        'section' => $g['section'],
                        'userAnswer' => $isCorrect ? strtoupper($right[$i]) : (isset($wrong[$i - count($right)]) ? strtoupper($wrong[$i - count($right)]) : null),
                        'correctAnswers' => [strtoupper($isCorrect ? $right[$i] : ($missing[$i - count($right)] ?? ''))],
                        'isCorrect' => $isCorrect,
                    ];
                }

                continue;
            }

            $value = is_array($given) ? ($given[0] ?? null) : $given;
            $userAnswer = is_string($value) && trim($value) !== '' ? trim($value) : null;
            $review[] = [
                'number' => $g['numbers'][0],
                'questionId' => $g['questionId'],
                'type' => $g['type'],
                'section' => $g['section'],
                'userAnswer' => $userAnswer,
                'correctAnswers' => array_map([$this, 'display'], $accepted),
                'isCorrect' => $userAnswer !== null && in_array($this->normalise($userAnswer), $accepted, true),
            ];
        }
        usort($review, fn ($a, $b) => $a['number'] <=> $b['number']);

        $byType = [];
        foreach ($review as $r) {
            $byType[$r['type']] ??= ['type' => $r['type'], 'correct' => 0, 'total' => 0];
            $byType[$r['type']]['total']++;
            $byType[$r['type']]['correct'] += $r['isCorrect'] ? 1 : 0;
        }

        return [
            'rawScore' => count(array_filter($review, fn ($r) => $r['isCorrect'])),
            'maxScore' => count($review),
            'review' => $review,
            'breakdown' => array_values($byType),
        ];
    }

    /** How many question numbers have an answer (for progress bars). */
    public function countAnswered(array $content, array $answers): int
    {
        $n = 0;
        foreach ($this->gradables($content) as $g) {
            $a = $answers[$g['questionId']] ?? null;
            if (is_array($a)) {
                $n += min(count($a), $g['select']);
            } elseif (is_string($a) && trim($a) !== '') {
                $n++;
            }
        }

        return $n;
    }

    public function normalise(string $answer): string
    {
        $answer = mb_strtolower(str_replace(['’', "'"], "'", $answer));
        $answer = preg_replace('/[.,;:!?"]/', '', $answer);

        return trim(preg_replace('/\s+/', ' ', $answer));
    }

    /** Letters and TRUE/FALSE/NOT GIVEN are shown upper-case; words as written. */
    private function display(string $answer): string
    {
        return preg_match('/^[a-h]$/', $answer) || in_array($answer, ['true', 'false', 'not given', 'yes', 'no'], true)
            ? strtoupper($answer)
            : $answer;
    }

    /** @return list<array{questionId: string, numbers: list<int>, type: string, section: int, select: int}> */
    private function gradables(array $content): array
    {
        $sections = $content['skill'] === 'listening' ? $content['parts'] : $content['sections'];
        $out = [];
        foreach ($sections as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['questions'] as $q) {
                    $out[] = [
                        'questionId' => $q['id'],
                        'numbers' => $group['type'] === 'multiple_choice' ? $q['numbers'] : [$q['number']],
                        'type' => $group['type'],
                        'section' => $section['number'],
                        'select' => $group['type'] === 'multiple_choice' ? $q['select'] : 1,
                    ];
                }
            }
        }

        return $out;
    }
}
