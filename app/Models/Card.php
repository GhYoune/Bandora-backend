<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['deck_id', 'word', 'definition', 'example', 'translation_fr', 'translation_ar', 'sort'])]
class Card extends Model
{
    use HasUlids;

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(CardProgress::class);
    }
}
