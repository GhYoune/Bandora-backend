<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A vocabulary deck the teacher writes in Filament. Unlocks like tests: free, or with a pack. */
#[Fillable(['title', 'description', 'topic', 'is_free', 'is_published', 'sort'])]
class Deck extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'is_free' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class)->orderBy('sort')->orderBy('created_at');
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /** free = everyone, included = unlocked by the pack, locked = needs a pack. */
    public function accessFor(User $user): string
    {
        if ($this->is_free) {
            return 'free';
        }

        return $user->hasActivePack() ? 'included' : 'locked';
    }
}
