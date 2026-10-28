<?php

namespace App\Services\Tests;

use App\Enums\Skill;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns the teacher's form (answers written inline) into the public test content of the API
 * contract plus a separate answer key.
 *
 * Teacher conventions:
 * - Gaps are written in the text with their answer: "Name: Sarah [[Mitchell]]".
 *   Alternatives use "|": "[[colour|color]]". With a word bank, write the letter or the word.
 * - Multiple choice: tick the correct option(s). Two ticked options = "Choose TWO" (two marks).
 * - Matching / labelling: the answer is the option key (A, B… or i, ii… for headings).
 *
 * Question numbers are assigned in order across the whole test. Ids are generated once and
 * written back into the form so they stay stable when the teacher edits later.
 */
class TestCompiler
{
    private int $number = 1;

    /** @var array<string, list<string>> */
    private array $key = [];

    public function compile(Skill $skill, array $form): CompiledTest
    {
        $this->number = 1;
        $this->key = [];

        [$form, $content] = match ($skill) {
            Skill::Listening => $this->listening($form),
            Skill::Reading => $this->reading($form),
            Skill::Writing => $this->writing($form),
            Skill::Speaking => $this->speaking($form),
        };

        $questionCount = match ($skill) {
            Skill::Listening, Skill::Reading => $this->number - 1,
            Skill::Writing => count($content['tasks']),
            Skill::Speaking => collect($content['parts'])->sum(
                fn (array $p) => $p['kind'] === 'cue_card' ? 1 + count($p['followUps']) : count($p['questions'])
            ),
        };

        return new CompiledTest($form, $content, $this->key, $questionCount);
    }

    /* ---------- skills ---------- */

    private function listening(array $form): array
    {
        $parts = [];
        $form['parts'] = $this->list($form['parts'] ?? []);
        foreach ($form['parts'] as $i => &$part) {
            $part['id'] ??= $this->newId();
            [$part['groups'], $groups] = $this->groups($part['groups'] ?? []);
            $parts[] = [
                'id' => $part['id'],
                'number' => $i + 1,
                'title' => filled($part['title'] ?? null) ? $part['title'] : 'Part '.($i + 1),
                'audioUrl' => $this->assetUrl($part['audio'] ?? null),
                'audioDurationSeconds' => (float) ($part['audio_duration'] ?? 0),
                'groups' => $groups,
            ];
        }

        return [$form, ['skill' => 'listening', 'parts' => $parts]];
    }

    private function reading(array $form): array
    {
        $sections = [];
        $form['sections'] = $this->list($form['sections'] ?? []);
        foreach ($form['sections'] as $i => &$section) {
            $section['id'] ??= $this->newId();
            $passages = [];
            $section['passages'] = $this->list($section['passages'] ?? []);
            foreach ($section['passages'] as &$passage) {
                $passage['id'] ??= $this->newId();
                $passages[] = [
                    'id' => $passage['id'],
                    'title' => (string) ($passage['title'] ?? ''),
                    'subtitle' => filled($passage['subtitle'] ?? null) ? $passage['subtitle'] : null,
                    'paragraphs' => collect($this->list($passage['paragraphs'] ?? []))
                        ->filter(fn (array $p) => filled($p['text'] ?? null))
                        ->map(fn (array $p) => [
                            'label' => filled($p['label'] ?? null) ? $p['label'] : null,
                            'text' => trim($p['text']),
                        ])->values()->all(),
                ];
            }
            [$section['groups'], $groups] = $this->groups($section['groups'] ?? []);
            $sections[] = [
                'id' => $section['id'],
                'number' => $i + 1,
                'title' => 'Section '.($i + 1),
                'passages' => $passages,
                'groups' => $groups,
            ];
        }

        return [$form, ['skill' => 'reading', 'sections' => $sections]];
    }

    private function writing(array $form): array
    {
        $tasks = [];
        $form['tasks'] = $this->list($form['tasks'] ?? []);
        foreach ($form['tasks'] as &$task) {
            $task['id'] ??= $this->newId();
            $number = (int) ($task['number'] ?? 2);
            $tasks[] = [
                'id' => $task['id'],
                'number' => $number,
                'kind' => $task['kind'] ?? ($number === 1 ? 'letter' : 'essay'),
                'prompt' => trim((string) ($task['prompt'] ?? '')),
                'bullets' => $this->lines($task['bullets'] ?? []),
                'closing' => filled($task['closing'] ?? null) ? $task['closing'] : null,
                'minWords' => (int) ($task['min_words'] ?? ($number === 1 ? 150 : 250)),
                'suggestedMinutes' => (int) ($task['suggested_minutes'] ?? ($number === 1 ? 20 : 40)),
            ];
        }
        usort($tasks, fn ($a, $b) => $a['number'] <=> $b['number']);

        return [$form, ['skill' => 'writing', 'tasks' => $tasks]];
    }

    private function speaking(array $form): array
    {
        $parts = [];
        foreach ([1 => 'interview', 3 => 'discussion'] as $n => $kind) {
            $key = "part{$n}";
            if (empty($form[$key]['questions'])) {
                continue;
            }
            $form[$key]['questions'] = $this->withIds($form[$key]['questions']);
            $parts[$n] = [
                'number' => $n,
                'kind' => $kind,
                'topic' => (string) ($form[$key]['topic'] ?? ''),
                'answerSeconds' => (int) ($form[$key]['answer_seconds'] ?? ($n === 1 ? 45 : 75)),
                'questions' => $this->speakingQuestions($form[$key]['questions']),
            ];
        }
        if (filled($form['part2']['topic'] ?? null)) {
            $form['part2']['id'] ??= $this->newId();
            $form['part2']['follow_ups'] = $this->withIds($form['part2']['follow_ups'] ?? []);
            $parts[2] = [
                'number' => 2,
                'kind' => 'cue_card',
                'id' => $form['part2']['id'],
                'topic' => $form['part2']['topic'],
                'bullets' => $this->lines($form['part2']['bullets'] ?? []),
                'closing' => (string) ($form['part2']['closing'] ?? ''),
                'prepSeconds' => (int) ($form['part2']['prep_seconds'] ?? 60),
                'speakSeconds' => (int) ($form['part2']['speak_seconds'] ?? 120),
                'followUps' => $this->speakingQuestions($form['part2']['follow_ups']),
            ];
        }
        ksort($parts);

        return [$form, ['skill' => 'speaking', 'parts' => array_values($parts)]];
    }

    private function speakingQuestions(array $questions): array
    {
        return collect($questions)
            ->filter(fn (array $q) => filled($q['text'] ?? null))
            ->map(fn (array $q) => ['id' => $q['id'], 'text' => trim($q['text'])])
            ->values()->all();
    }

    /* ---------- question groups (Filament Builder blocks) ---------- */

    /** @return array{0: array, 1: array} the form blocks (with ids) and the compiled groups */
    private function groups(array $blocks): array
    {
        $blocks = $this->list($blocks);
        $groups = [];
        foreach ($blocks as &$block) {
            $data = $block['data'] ?? [];
            $data['id'] ??= $this->newId();
            $first = $this->number;
            [$data, $group] = match ($block['type']) {
                'multiple_choice' => $this->multipleChoice($data),
                'true_false_not_given' => $this->tfng($data),
                'matching' => $this->matching($data),
                'gap_fill' => $this->gapFill($data),
                'table_completion' => $this->table($data),
                'diagram_label' => $this->diagram($data),
                'short_answer' => $this->shortAnswer($data),
            };
            $block['data'] = $data;
            if ($this->number === $first) {
                continue; // a block without questions is not shown to students
            }
            $groups[] = [
                'id' => $data['id'],
                'type' => $block['type'],
                'instructions' => filled($data['instructions'] ?? null) ? $data['instructions'] : $group['defaultInstructions'],
                'firstNumber' => $first,
                'lastNumber' => $this->number - 1,
                ...collect($group)->except('defaultInstructions')->all(),
            ];
        }

        return [$blocks, $groups];
    }

    private function multipleChoice(array $d): array
    {
        $questions = [];
        $maxOptions = 0;
        $multi = false;
        $d['questions'] = $this->withIds($d['questions'] ?? []);
        foreach ($d['questions'] as $q) {
            $options = $this->list($q['options'] ?? []);
            $keys = $this->letters(count($options));
            $correct = collect($options)->keys()->filter(fn ($i) => (bool) ($options[$i]['correct'] ?? false))
                ->map(fn ($i) => $keys[$i])->values()->all();
            $select = max(1, count($correct));
            $multi = $multi || $select > 1;
            $maxOptions = max($maxOptions, count($options));
            $questions[] = [
                'id' => $q['id'],
                'numbers' => $this->take($select),
                'prompt' => trim((string) ($q['prompt'] ?? '')),
                'options' => collect($options)->values()->map(fn ($o, $i) => ['key' => $keys[$i], 'text' => trim((string) ($o['text'] ?? ''))])->all(),
                'select' => $select,
            ];
            $this->key[$q['id']] = array_map('strtolower', $correct);
        }
        $range = $this->letterRange($maxOptions);

        return [$d, [
            'defaultInstructions' => $multi ? "Choose TWO letters, {$range}." : 'Choose the correct letter, '.$this->letterList($maxOptions).'.',
            'questions' => $questions,
        ]];
    }

    private function tfng(array $d): array
    {
        $variant = ($d['variant'] ?? 'tfng') === 'ynng' ? 'ynng' : 'tfng';
        $d['questions'] = $this->withIds($d['questions'] ?? []);
        $questions = [];
        foreach ($d['questions'] as $q) {
            $questions[] = ['id' => $q['id'], 'number' => $this->take(1)[0], 'prompt' => trim((string) ($q['statement'] ?? ''))];
            $this->key[$q['id']] = [strtolower((string) ($q['answer'] ?? ''))];
        }
        $words = $variant === 'ynng' ? 'YES, NO or NOT GIVEN' : 'TRUE, FALSE or NOT GIVEN';

        return [$d, [
            'defaultInstructions' => "Do the following statements agree with the information in the text? Choose {$words}.",
            'variant' => $variant,
            'questions' => $questions,
        ]];
    }

    private function matching(array $d): array
    {
        $variant = $d['variant'] ?? 'general';
        $options = collect($this->list($d['options'] ?? []))->map(fn ($o) => $this->text($o))->filter()->values();
        $keys = $variant === 'headings' ? $this->romans($options->count()) : $this->letters($options->count());
        $d['questions'] = $this->withIds($d['questions'] ?? []);
        $questions = [];
        foreach ($d['questions'] as $q) {
            $questions[] = ['id' => $q['id'], 'number' => $this->take(1)[0], 'prompt' => trim((string) ($q['prompt'] ?? ''))];
            $this->key[$q['id']] = $this->answers((string) ($q['answer'] ?? ''));
        }

        return [$d, [
            'defaultInstructions' => $variant === 'headings'
                ? 'Choose the correct heading for each paragraph from the list of headings.'
                : 'Choose the correct letter for each statement.'.(($d['allow_reuse'] ?? false) ? ' You may use any letter more than once.' : ''),
            'variant' => $variant,
            'optionsTitle' => filled($d['options_title'] ?? null) ? $d['options_title'] : ($variant === 'headings' ? 'List of Headings' : 'Options'),
            'options' => $options->map(fn ($text, $i) => ['key' => $keys[$i], 'text' => $text])->all(),
            'allowReuse' => (bool) ($d['allow_reuse'] ?? false),
            'questions' => $questions,
        ]];
    }

    private function gapFill(array $d): array
    {
        $bank = collect($this->list($d['word_bank'] ?? []))->map(fn ($o) => $this->text($o))->filter()->values();
        $bankKeys = $this->letters($bank->count());
        $options = $bank->map(fn ($text, $i) => ['key' => $bankKeys[$i], 'text' => $text])->all();
        [$content, $questions] = $this->extractGaps((string) ($d['text'] ?? ''), $d['id'], 0, $options);

        return [$d, [
            'defaultInstructions' => $options ? 'Complete the summary using the list of words below.' : 'Complete the notes below.',
            'layout' => $d['layout'] ?? 'notes',
            'title' => filled($d['title'] ?? null) ? $d['title'] : null,
            'wordLimit' => filled($d['word_limit'] ?? null) ? $d['word_limit'] : null,
            'content' => $content,
            ...($options ? ['options' => $options] : []),
            'questions' => $questions,
        ]];
    }

    private function table(array $d): array
    {
        $rows = [];
        $questions = [];
        $gapIndex = 0;
        foreach ($this->list($d['rows'] ?? []) as $row) {
            $cells = [];
            foreach ($this->cells($this->text($row, 'cells')) as $cell) {
                [$text, $found] = $this->extractGaps($cell, $d['id'], $gapIndex);
                $gapIndex += count($found);
                $questions = [...$questions, ...$found];
                $cells[] = $text;
            }
            $rows[] = $cells;
        }

        return [$d, [
            'defaultInstructions' => 'Complete the table below.',
            'title' => filled($d['title'] ?? null) ? $d['title'] : null,
            'wordLimit' => filled($d['word_limit'] ?? null) ? $d['word_limit'] : null,
            'headers' => $this->cells((string) ($d['headers'] ?? '')),
            'rows' => $rows,
            'questions' => $questions,
        ]];
    }

    private function diagram(array $d): array
    {
        $letters = ($d['mode'] ?? 'letters') === 'letters' ? $this->letters((int) ($d['letters'] ?? 8)) : [];
        $d['questions'] = $this->withIds($d['questions'] ?? []);
        $questions = [];
        foreach ($d['questions'] as $q) {
            $questions[] = array_filter([
                'id' => $q['id'],
                'number' => $this->take(1)[0],
                'prompt' => trim((string) ($q['prompt'] ?? '')),
                'x' => isset($q['x']) && $q['x'] !== '' ? (float) $q['x'] : null,
                'y' => isset($q['y']) && $q['y'] !== '' ? (float) $q['y'] : null,
            ], fn ($v) => $v !== null);
            $this->key[$q['id']] = $this->answers((string) ($q['answer'] ?? ''));
        }

        return [$d, [
            'defaultInstructions' => $letters
                ? 'Label the map below. Write the correct letter, '.$this->letterRange(count($letters)).', next to each question.'
                : 'Label the diagram below.',
            'title' => filled($d['title'] ?? null) ? $d['title'] : null,
            'imageUrl' => $this->assetUrl($d['image'] ?? null),
            'imageAlt' => (string) ($d['image_alt'] ?? ''),
            'wordLimit' => filled($d['word_limit'] ?? null) ? $d['word_limit'] : null,
            ...($letters ? ['options' => array_map(fn ($k) => ['key' => $k, 'text' => $k], $letters)] : []),
            'questions' => $questions,
        ]];
    }

    private function shortAnswer(array $d): array
    {
        $d['questions'] = $this->withIds($d['questions'] ?? []);
        $questions = [];
        foreach ($d['questions'] as $q) {
            $questions[] = ['id' => $q['id'], 'number' => $this->take(1)[0], 'prompt' => trim((string) ($q['prompt'] ?? ''))];
            $this->key[$q['id']] = $this->answers((string) ($q['answer'] ?? ''));
        }

        return [$d, [
            'defaultInstructions' => 'Answer the questions below.',
            'wordLimit' => filled($d['word_limit'] ?? null) ? $d['word_limit'] : null,
            'questions' => $questions,
        ]];
    }

    /**
     * Replaces "[[answer|alt]]" with "[[gapId]]" and records the answers.
     * Gap ids are "{blockId}-{index}", stable as long as gaps are not reordered.
     *
     * @return array{0: string, 1: list<array{id: string, number: int}>}
     */
    private function extractGaps(string $text, string $blockId, int $offset, array $options = []): array
    {
        $questions = [];
        $index = $offset;
        $compiled = preg_replace_callback('/\[\[(.*?)\]\]/', function (array $m) use (&$questions, &$index, $blockId, $options) {
            $id = $blockId.'-'.$index++;
            $answers = $this->answers($m[1]);
            if ($options) {
                // With a word bank the teacher may write the letter or the word itself.
                $answers = array_map(function (string $a) use ($options) {
                    foreach ($options as $o) {
                        if (strtolower($o['text']) === $a) {
                            return strtolower($o['key']);
                        }
                    }

                    return $a;
                }, $answers);
            }
            $this->key[$id] = $answers;
            $questions[] = ['id' => $id, 'number' => $this->take(1)[0]];

            return "[[{$id}]]";
        }, str_replace("\r\n", "\n", trim($text)));

        return [$compiled, $questions];
    }

    /* ---------- helpers ---------- */

    /** @return list<int> the next $count question numbers */
    private function take(int $count): array
    {
        $numbers = range($this->number, $this->number + $count - 1);
        $this->number += $count;

        return $numbers;
    }

    /** Filament keys repeater/builder items by UUID; order is what matters. */
    private function list(mixed $items): array
    {
        return is_array($items) ? array_values($items) : [];
    }

    /** Filament "simple" repeaters store plain strings; full repeaters store ['text' => …]. */
    private function text(mixed $item, string $field = 'text'): string
    {
        return trim(is_array($item) ? (string) ($item[$field] ?? '') : (string) $item);
    }

    private function withIds(array $items): array
    {
        return array_map(function ($item) {
            $item = is_array($item) ? $item : ['text' => (string) $item];
            $item['id'] ??= $this->newId();

            return $item;
        }, $this->list($items));
    }

    private function newId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /** @return list<string> accepted answers, lower-case, "|" separated */
    private function answers(string $raw): array
    {
        return collect(explode('|', $raw))
            ->map(fn ($a) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $a))))
            ->filter(fn ($a) => $a !== '')
            ->values()->all();
    }

    /** Bullets come from a textarea (one per line) or a tags input. */
    private function lines(mixed $value): array
    {
        $lines = is_array($value) ? $value : preg_split('/\r?\n/', (string) $value);

        return collect($lines)->map(fn ($l) => trim((string) $l))->filter()->values()->all();
    }

    /** Table cells are written "one | two | three"; a "|" inside [[answer|alt]] is not a cell break. */
    private function cells(string $line): array
    {
        return array_map('trim', preg_split('/\|(?![^\[]*\]\])/', $line));
    }

    private function letters(int $count): array
    {
        return array_slice(range('A', 'Z'), 0, max(0, $count));
    }

    private function romans(int $count): array
    {
        $all = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x', 'xi', 'xii', 'xiii', 'xiv', 'xv'];

        return array_slice($all, 0, $count);
    }

    private function letterRange(int $count): string
    {
        return $count > 0 ? 'A–'.chr(64 + $count) : '';
    }

    /** "A, B or C" */
    private function letterList(int $count): string
    {
        $letters = $this->letters(max($count, 2));

        return implode(', ', array_slice($letters, 0, -1)).' or '.end($letters);
    }

    private function assetUrl(?string $path): string
    {
        if (blank($path)) {
            return '';
        }
        if (preg_match('#^(https?:)?/#', $path)) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
