<?php

namespace App\Support;

use App\Enums\Skill;

/**
 * IELTS band arithmetic. Raw-score tables are the usual published approximations:
 * official cut-offs vary slightly between test versions, so results are "estimated bands".
 */
final class Bands
{
    /** [minimum raw score, band], highest first */
    private const LISTENING = [
        [39, 9], [37, 8.5], [35, 8], [32, 7.5], [30, 7], [26, 6.5], [23, 6], [18, 5.5],
        [16, 5], [13, 4.5], [10, 4], [8, 3.5], [6, 3], [4, 2.5], [2, 2], [1, 1], [0, 0],
    ];

    private const GT_READING = [
        [40, 9], [39, 8.5], [37, 8], [36, 7.5], [34, 7], [32, 6.5], [30, 6], [27, 5.5],
        [23, 5], [19, 4.5], [15, 4], [12, 3.5], [9, 3], [6, 2.5], [3, 2], [1, 1], [0, 0],
    ];

    public static function fromRawScore(Skill $skill, int $raw): float
    {
        $table = $skill === Skill::Listening ? self::LISTENING : self::GT_READING;
        foreach ($table as [$min, $band]) {
            if ($raw >= $min) {
                return (float) $band;
            }
        }

        return 0.0;
    }

    /** Nearest half band; .25 and .75 round up, as IELTS does. */
    public static function round(float $value): float
    {
        return floor($value * 2 + 0.5) / 2;
    }

    /** Writing: Task 2 counts twice as much as Task 1. */
    public static function writing(?float $task1, ?float $task2): ?float
    {
        if ($task1 !== null && $task2 !== null) {
            return self::round(($task1 + 2 * $task2) / 3);
        }

        return $task1 ?? $task2;
    }
}
