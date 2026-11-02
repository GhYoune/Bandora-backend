<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's "I know it" / "Still learning" answer for one card. No row = new. */
#[Fillable(['user_id', 'card_id', 'status'])]
class CardProgress extends Model
{
    protected $table = 'card_progress';

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
