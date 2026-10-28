<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

#[Fillable(['attempt_id', 'question_id', 'path', 'duration_seconds'])]
class Recording extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['duration_seconds' => 'float'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    /** Recordings are private; the app plays them through a short-lived signed link. */
    public function url(): string
    {
        return URL::temporarySignedRoute('recordings.audio', now()->addHours(2), ['recording' => $this->id]);
    }
}
