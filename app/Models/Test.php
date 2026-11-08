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
use Illuminate\Validation\ValidationException;

#[Fillable(['user_id', 'skill', 'title', 'description', 'source', 'is_free', 'is_published', 'duration_seconds', 'sort', 'form'])]
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
            // Incomplete tests can be saved as drafts, but never shown to students.
            if ($test->is_published && $problems = $test->publishProblems()) {
                throw ValidationException::withMessages(['is_published' => 'This test cannot be published yet: '.implode(' ', $problems)]);
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

    /**
     * What stops this test from being published, in the teacher's words. Empty = ready.
     *
     * @return list<string>
     */
    public function publishProblems(): array
    {
        $content = $this->content ?? [];
        $problems = [];
        $missingAnswers = collect($this->answer_key ?? [])->filter(fn ($a) => $a === [] || $a === [''])->count();

        if ($this->skill === Skill::Listening || $this->skill === Skill::Reading) {
            $listening = $this->skill === Skill::Listening;
            $containers = $content[$listening ? 'parts' : 'sections'] ?? [];
            $expected = $listening ? 4 : 3;
            if (count($containers) !== $expected) {
                $problems[] = ($listening ? 'It needs 4 parts' : 'It needs 3 sections').', it has '.count($containers).'.';
            }
            if ($this->question_count !== 40) {
                $problems[] = "It has {$this->question_count} questions instead of 40.";
            }
            if ($missingAnswers) {
                $problems[] = "{$missingAnswers} question".($missingAnswers > 1 ? 's have' : ' has').' no answer.';
            }
            foreach ($containers as $c) {
                $label = ($listening ? 'Part ' : 'Section ').$c['number'];
                if ($listening && blank($c['audioUrl'] ?? null)) {
                    $problems[] = "{$label} has no recording.";
                }
                foreach ($c['passages'] ?? [] as $passage) {
                    if (empty($passage['paragraphs'])) {
                        $problems[] = "{$label}: the text \"{$passage['title']}\" has no paragraphs or pictures.";
                    }
                }
                if (! $listening && empty($c['passages'])) {
                    $problems[] = "{$label} has no text to read.";
                }
                foreach ($c['groups'] as $g) {
                    if ($g['type'] === 'diagram_label' && blank($g['imageUrl'] ?? null)) {
                        $problems[] = "{$label}: questions {$g['firstNumber']}–{$g['lastNumber']} need their picture.";
                    }
                }
            }
        } elseif ($this->skill === Skill::Writing) {
            if (collect($content['tasks'] ?? [])->filter(fn ($t) => filled($t['prompt'] ?? null))->isEmpty()) {
                $problems[] = 'It has no task.';
            }
        } elseif (empty($content['parts'])) {
            $problems[] = 'It has no Speaking part.';
        }

        return $problems;
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
