<?php

namespace App\Models;

use App\Enums\Skill;
use App\Services\Tests\TestCompiler;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'skill', 'title', 'description', 'is_free', 'is_published', 'duration_seconds', 'sort', 'form'])]
class Test extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'skill' => Skill::class,
            'is_free' => 'boolean',
            'is_published' => 'boolean',
            'form' => 'array',
            'content' => 'array',
            'answer_key' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // The public content and answer key are always derived from what the teacher edits.
        static::saving(function (Test $test) {
            if ($test->isDirty(['form', 'skill']) || ! $test->content) {
                $compiled = app(TestCompiler::class)->compile($test->skill, $test->form ?? []);
                $test->form = $compiled->form;
                $test->content = $compiled->content;
                $test->answer_key = $compiled->answerKey;
                $test->question_count = $compiled->questionCount;
            }
        });
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    /** The student who wrote this test (custom practice); null for the teacher's tests. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isCustom(): bool
    {
        return $this->user_id !== null;
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true)->whereNull('user_id');
    }

    /** The teacher's tests, without students' custom practice. */
    public function scopeCatalog(Builder $query): void
    {
        $query->whereNull('user_id');
    }

    /** Writing: which tasks the test contains. */
    public function writingTasks(): ?string
    {
        if ($this->skill !== Skill::Writing) {
            return null;
        }
        $numbers = collect($this->content['tasks'] ?? [])->pluck('number')->sort()->values()->all();

        return match ($numbers) {
            [1] => 'task1',
            [2] => 'task2',
            default => 'both',
        };
    }

    /** Credits charged on submit (Speaking: the maximum, one per part answered). */
    public function creditCost(): ?int
    {
        return match ($this->skill) {
            Skill::Writing => count($this->content['tasks'] ?? []),
            Skill::Speaking => count($this->content['parts'] ?? []),
            default => null,
        };
    }
}
