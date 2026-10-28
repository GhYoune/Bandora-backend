<?php

namespace App\Enums;

enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Marking = 'marking';
    case Completed = 'completed';
    case MarkingFailed = 'marking_failed';
}
