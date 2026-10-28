<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Skill: string implements HasLabel
{
    case Listening = 'listening';
    case Reading = 'reading';
    case Writing = 'writing';
    case Speaking = 'speaking';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    /** Listening and Reading are marked automatically; Writing and Speaking by AI. */
    public function isObjective(): bool
    {
        return in_array($this, [self::Listening, self::Reading], true);
    }
}
