<?php

namespace App\Filament\Resources\Tests\Pages;

use App\Enums\Skill;
use App\Models\Test;
use Filament\Notifications\Notification;

/**
 * "Published" on an incomplete test must not throw the teacher's work away (uploads included):
 * the test is saved as a draft and she is told what is still missing.
 */
trait SavesAsDraftWhenIncomplete
{
    /** @var list<string> */
    protected array $keptAsDraftBecause = [];

    protected function keepAsDraftIfIncomplete(array $data): array
    {
        $this->keptAsDraftBecause = [];
        if (empty($data['is_published'])) {
            return $data;
        }
        $skill = $data['skill'] instanceof Skill ? $data['skill'] : Skill::from($data['skill']);
        $problems = Test::problemsFor($skill, $data['form'] ?? []);
        if ($problems) {
            $data['is_published'] = false;
            $this->keptAsDraftBecause = $problems;
        }

        return $data;
    }

    protected function notifyIfKeptAsDraft(): void
    {
        if (! $this->keptAsDraftBecause) {
            return;
        }
        Notification::make()
            ->warning()
            ->title('Saved as a draft, not published yet')
            ->body(implode(' ', $this->keptAsDraftBecause))
            ->persistent()
            ->send();
    }
}
