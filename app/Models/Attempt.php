<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'test_id', 'status', 'listening_plays', 'answers', 'flagged', 'remaining_seconds', 'position', 'raw_score', 'band', 'credits_charged', 'result', 'started_at', 'completed_at'])]
class Attempt extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'answers' => 'array',
            'flagged' => 'array',
            'position' => 'array',
            'result' => 'array',
            'band' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(Recording::class);
    }

    public function isOpen(): bool
    {
        return $this->status === AttemptStatus::InProgress;
    }

    /** Listening heard twice: shown, but excluded from the estimated band. */
    public function practiceConditions(): bool
    {
        return $this->listening_plays === 2;
    }
}
