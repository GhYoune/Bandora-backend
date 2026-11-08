<?php

namespace App\Services\Tests;

use App\Enums\Skill;

/**
 * Turns a Listening or Reading test pasted as plain text into the Filament `form` structure that
 * TestCompiler understands. The format is described in docs/import-format.md.
 *
 * Problems are reported per line: `errors` mean content would be lost or misread (the import is
 * refused), `warnings` mean the test is incomplete (it can be saved as a draft, not published).
 */
class TestImporter
{
    /** Type keyword → [Filament block type, variant/layout/mode]. */
    public const TYPES = [
        'notes' => ['gap_fill', 'notes'],
        'form' => ['gap_fill', 'form'],
        'summary' => ['gap_fill', 'summary'],
        'sentences' => ['gap_fill', 'sentences'],
        'flow-chart' => ['gap_fill', 'flow_chart'],
        'table' => ['table_completion', null],
        'choice' => ['multiple_choice', null],
        'tfng' => ['true_false_not_given', 'tfng'],
        'ynng' => ['true_false_not_given', 'ynng'],
        'matching' => ['matching', 'general'],
        'matching-information' => ['matching', 'information'],
        'matching-features' => ['matching', 'features'],
        'sentence-endings' => ['matching', 'sentence_endings'],
        'headings' => ['matching', 'headings'],
        'map' => ['diagram_label', 'letters'],
        'diagram' => ['diagram_label', 'words'],
        'short-answer' => ['short_answer', null],
    ];

    private const META = ['type', 'instructions', 'word limit', 'title', 'options title', 'reuse', 'image', 'letters'];

    private Skill $skill;

    /** @var list<array{line: int|null, message: string}> */
    private array $errors = [];

    /** @var list<array{line: int|null, message: string}> */
    private array $warnings = [];

    public function __construct(private TestCompiler $compiler) {}

    /**
     * @return array{
     *     form: array,
     *     content: array|null,
     *     answerKey: array,
     *     questionCount: int,
     *     errors: list<array{line: int|null, message: string}>,
     *     warnings: list<array{line: int|null, message: string}>,
     *     images: list<array{key: string, description: string, line: int}>
     * }
     */
    public function parse(Skill $skill, string $text): array
    {
        $this->skill = $skill;
        $this->errors = [];
        $this->warnings = [];

        if (! $skill->isObjective()) {
            $this->error(null, 'Only Listening and Reading tests can be imported. Enter Writing and Speaking in the normal form.');

            return $this->result([], null, [], 0, []);
        }

        $containers = $this->scan(str_replace(["\r\n", "\r"], "\n", $text));
        if ($containers === [] && $this->errors === []) {
            $this->error(null, $skill === Skill::Listening ? 'Start with a "# Part 1" line.' : 'Start with a "# Section 1" line.');
        }

        // Build the Filament form, remembering which parsed group became which block.
        $images = [];
        $form = [];
        $groupRefs = [];
        foreach ($containers as $c => $container) {
            $blocks = [];
            foreach ($container['groups'] as $g => $group) {
                $block = $this->block($group);
                if ($block === null) {
                    continue;
                }
                if ($block['type'] === 'diagram_label') {
                    // Key = container index . block index, the block's position in the stored form.
                    $images[] = ['key' => $c.'.'.count($blocks), 'description' => $block['data']['image_alt'] ?: 'Picture for questions '.$group['label'], 'line' => $group['line']];
                }
                $groupRefs[] = ['container' => $c, 'block' => count($blocks), 'group' => $group];
                $blocks[] = $block;
            }
            $form[] = $skill === Skill::Listening
                ? ['title' => $container['title'], 'groups' => $blocks]
                : ['passages' => $container['passages'], 'groups' => $blocks];
        }
        $form = $skill === Skill::Listening ? ['parts' => $form] : ['sections' => $form];

        $compiled = $this->compiler->compile($skill, $form);
        $this->checkNumbering($compiled, $groupRefs);
        $this->checkTotals($compiled);

        return $this->result($compiled->form, $compiled->content, $compiled->answerKey, $compiled->questionCount, $images);
    }

    /**
     * Puts uploaded pictures into the map/diagram blocks. $images maps an image key from parse() to a stored path.
     */
    public function withImages(array $form, array $images): array
    {
        $containers = isset($form['parts']) ? 'parts' : 'sections';
        foreach ($images as $key => $path) {
            [$c, $b] = array_map('intval', explode('.', (string) $key));
            if (filled($path) && isset($form[$containers][$c]['groups'][$b]['data'])) {
                $form[$containers][$c]['groups'][$b]['data']['image'] = is_array($path) ? reset($path) : $path;
            }
        }

        return $form;
    }

    /* ---------- scanning ---------- */

    private function scan(string $text): array
    {
        $containers = [];
        $container = null;
        $passage = null;
        $group = null;
        $paragraph = null;
        $mode = 'start';
        $warnedStray = false;
        $word = $this->skill === Skill::Listening ? 'part' : 'section';

        $closeParagraph = function () use (&$paragraph, &$passage) {
            if ($paragraph !== null && $passage !== null) {
                $passage['paragraphs'][] = $paragraph;
            }
            $paragraph = null;
        };
        $closePassage = function () use (&$passage, &$container, $closeParagraph) {
            $closeParagraph();
            if ($passage !== null) {
                if ($passage['paragraphs'] === []) {
                    $this->error($passage['line'], "The text \"{$passage['title']}\" has no paragraphs.");
                }
                unset($passage['line']);
                $container['passages'][] = $passage;
            }
            $passage = null;
        };
        $closeGroup = function () use (&$group, &$container) {
            if ($group !== null) {
                $container['groups'][] = $group;
            }
            $group = null;
        };
        $closeContainer = function () use (&$container, &$containers, $closeGroup, $closePassage) {
            $closePassage();
            $closeGroup();
            if ($container !== null) {
                $containers[] = $container;
            }
            $container = null;
        };

        foreach (explode("\n", $text) as $i => $raw) {
            $n = $i + 1;
            $line = rtrim($raw);
            $trim = trim($line);

            // "# Part 2" / "# Section 1"
            if (preg_match('/^#\s+(part|section)\b\s*(\d+)?/i', $trim, $m)) {
                $closeContainer();
                if (strtolower($m[1]) !== $word) {
                    $this->error($n, ucfirst($this->skill->value).' tests use "# '.ucfirst($word)." N\", not \"# {$m[1]}\".");
                }
                $container = ['line' => $n, 'title' => null, 'passages' => [], 'groups' => []];
                $mode = 'container';

                continue;
            }
            // "## Text: Title" (Reading)
            if (preg_match('/^##\s+(?:text|passage)\s*:?\s*(.*)$/i', $trim, $m)) {
                if ($this->skill !== Skill::Reading) {
                    $this->error($n, 'Texts ("## Text:") are only for Reading tests.');

                    continue;
                }
                if ($container === null) {
                    $this->error($n, 'Put "# Section N" before the first text.');

                    continue;
                }
                $closeGroup();
                $closePassage();
                $passage = ['line' => $n, 'title' => trim($m[1]) ?: 'Text', 'subtitle' => null, 'paragraphs' => []];
                $mode = 'passage';

                continue;
            }
            // "Questions 1–5" / "Question 14"
            if (preg_match('/^questions?\s+(\d+)(?:\s*(?:-|–|—|to|and)\s*(\d+))?\s*:?\s*$/iu', $trim, $m)) {
                if ($container === null) {
                    $this->error($n, 'Put "# '.ucfirst($word).' N" before the first questions.');

                    continue;
                }
                $closePassage();
                $closeGroup();
                $first = (int) $m[1];
                $last = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $first;
                $group = ['line' => $n, 'label' => $first === $last ? "{$first}" : "{$first}–{$last}", 'first' => $first, 'last' => $last,
                    'meta' => [], 'body' => [], 'options' => []];
                $mode = 'meta';

                continue;
            }

            if ($container === null) {
                if ($trim !== '' && ! $warnedStray) {
                    $this->error($n, 'Text before the first "# '.ucfirst($word).' 1" line is ignored.');
                    $warnedStray = true;
                }

                continue;
            }

            switch ($mode) {
                case 'container':
                    if ($trim === '') {
                        break;
                    }
                    if (preg_match('/^title\s*:\s*(.+)$/i', $trim, $m)) {
                        $container['title'] = trim($m[1]);
                    } else {
                        $this->error($n, $this->skill === Skill::Reading
                            ? 'Expected "## Text: …" or "Questions …" here.'
                            : 'Expected "Title: …" or "Questions …" here.');
                    }
                    break;

                case 'passage':
                    if ($trim === '') {
                        $closeParagraph();
                    } elseif ($passage['paragraphs'] === [] && $paragraph === null && $passage['subtitle'] === null && preg_match('/^subtitle\s*:\s*(.+)$/i', $trim, $m)) {
                        $passage['subtitle'] = trim($m[1]);
                    } elseif ($paragraph === null) {
                        $paragraph = preg_match('/^\[([A-Z]{1,2})\]\s*(.*)$/', $trim, $m)
                            ? ['label' => $m[1], 'text' => $m[2]]
                            : ['label' => null, 'text' => $trim];
                    } else {
                        $paragraph['text'] .= ' '.$trim;
                    }
                    break;

                case 'meta':
                    if ($trim === '') {
                        break;
                    }
                    if (preg_match('/^([a-z ]+?)\s*:\s*(.*)$/i', $trim, $m) && in_array(strtolower(trim($m[1])), self::META, true)) {
                        $group['meta'][strtolower(trim($m[1]))] = ['value' => trim($m[2]), 'line' => $n];
                        break;
                    }
                    $mode = 'body';
                    // the line is the first body line
                    // no break
                case 'body':
                    if (preg_match('/^options\s*:\s*$/i', $trim)) {
                        $mode = 'options';
                        break;
                    }
                    $group['body'][] = ['line' => $n, 'text' => $line];
                    break;

                case 'options':
                    if ($trim === '') {
                        break;
                    }
                    if (preg_match('/^([A-Za-z]{1,4})[\).]\s+(.+)$/', $trim, $m)) {
                        $group['options'][] = ['line' => $n, 'text' => trim($m[2])];
                        break;
                    }
                    $mode = 'body';
                    $group['body'][] = ['line' => $n, 'text' => $line];
                    break;
            }
        }
        $closeContainer();

        return $containers;
    }

    /* ---------- one question group → one Filament block ---------- */

    private function block(array $g): ?array
    {
        $typeWord = strtolower($g['meta']['type']['value'] ?? '');
        if ($typeWord === '') {
            $this->error($g['line'], "Questions {$g['label']}: add a \"Type:\" line, e.g. Type: notes.");

            return null;
        }
        if (! isset(self::TYPES[$typeWord])) {
            $this->error($g['meta']['type']['line'], "Unknown type \"{$typeWord}\". Use one of: ".implode(', ', array_keys(self::TYPES)).'.');

            return null;
        }
        [$type, $variant] = self::TYPES[$typeWord];
        $meta = fn (string $k) => $g['meta'][$k]['value'] ?? null;
        $body = array_values(array_filter($g['body'], fn ($l) => trim($l['text']) !== ''));
        $data = array_filter([
            'instructions' => $meta('instructions'),
            'title' => $meta('title'),
            'word_limit' => $meta('word limit'),
        ], fn ($v) => filled($v));
        $data = match ($type) {
            'gap_fill' => $this->gapFill($g, $data, $variant),
            'table_completion' => $this->table($g, $data, $body),
            'multiple_choice' => $this->choice($g, $data, $body),
            'true_false_not_given' => $this->tfng($g, $data, $body, $variant),
            'matching' => $this->matching($g, $data, $body, $variant),
            'diagram_label' => $this->diagram($g, $data, $body, $variant),
            'short_answer' => ['questions' => $this->answered($g, $body), ...$data],
        };
        if ($data === null) {
            return null;
        }

        return ['type' => $type, 'data' => $data];
    }

    private function gapFill(array $g, array $data, string $layout): ?array
    {
        $text = trim(implode("\n", array_map(fn ($l) => $l['text'], $g['body'])));
        if (! str_contains($text, '[[')) {
            $this->error($g['line'], "Questions {$g['label']}: no gaps found. Write each answer in the text as [[answer]].");

            return null;
        }
        $bank = array_map(fn ($o) => $o['text'], $g['options']);
        if ($bank) {
            preg_match_all('/\[\[(.*?)\]\]/', $text, $m);
            $letters = array_map('strtolower', array_slice(range('A', 'Z'), 0, count($bank)));
            $words = array_map('strtolower', $bank);
            foreach ($m[1] as $answer) {
                $a = strtolower(trim(explode('|', $answer)[0]));
                if (! in_array($a, $letters, true) && ! in_array($a, $words, true)) {
                    $this->error($g['line'], "Questions {$g['label']}: the answer \"{$answer}\" is not a letter or word from the options list.");
                }
            }
        }

        return [...$data, 'layout' => $layout, 'text' => $text, 'word_bank' => $bank];
    }

    private function table(array $g, array $data, array $body): ?array
    {
        $rows = array_map(fn ($l) => trim(trim($l['text']), '|'), $body);
        if (count($rows) < 2) {
            $this->error($g['line'], "Questions {$g['label']}: a table needs a header line and at least one row, cells separated by \"|\".");

            return null;
        }
        $width = count(preg_split('/\|(?![^\[]*\]\])/', $rows[0]));
        foreach (array_slice($body, 1) as $i => $l) {
            $cells = count(preg_split('/\|(?![^\[]*\]\])/', $rows[$i + 1]));
            if ($cells !== $width) {
                $this->warn($l['line'], "This table row has {$cells} cells but the header has {$width}.");
            }
        }

        return [...$data, 'headers' => trim($rows[0]), 'rows' => array_map(fn ($r) => ['cells' => trim($r)], array_slice($rows, 1))];
    }

    private function choice(array $g, array $data, array $body): ?array
    {
        $questions = [];
        $q = null;
        foreach ($body as $l) {
            $t = trim($l['text']);
            if (preg_match('/^(\d+)(?:\s*(?:-|–|—|and|&)\s*(\d+))?\s*[.)]\s*(.+)$/u', $t, $m)) {
                if ($q) {
                    $questions[] = $q;
                }
                $q = ['line' => $l['line'], 'span' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] - (int) $m[1] + 1 : 1, 'prompt' => trim($m[3]), 'options' => []];
            } elseif (preg_match('/^(\*)?\s*([A-Za-z])\s*[\).]\s*(.+)$/', $t, $m) && $q) {
                $q['options'][] = ['text' => trim($m[3]), 'correct' => $m[1] === '*'];
            } elseif ($q && $q['options']) {
                $q['options'][count($q['options']) - 1]['text'] .= ' '.$t;
            } elseif ($q) {
                $q['prompt'] .= ' '.$t;
            } else {
                $this->error($l['line'], 'Expected a numbered question here, e.g. "6. Why is the caller phoning?".');
            }
        }
        if ($q) {
            $questions[] = $q;
        }
        foreach ($questions as $q) {
            $correct = count(array_filter($q['options'], fn ($o) => $o['correct']));
            if (count($q['options']) < 2) {
                $this->error($q['line'], 'This question needs at least two options, written "A) …", "B) …".');
            } elseif ($correct === 0) {
                $this->error($q['line'], 'Mark the correct option with "*", e.g. "*B) …".');
            } elseif ($correct !== $q['span']) {
                $this->error($q['line'], $q['span'] > 1
                    ? "This question covers {$q['span']} numbers, so mark {$q['span']} correct options."
                    : 'Only one option is marked here. For "Choose TWO", number the question like "21–22.".');
            }
        }

        return [...$data, 'questions' => array_map(fn ($q) => ['prompt' => $q['prompt'], 'options' => $q['options']], $questions)];
    }

    private function tfng(array $g, array $data, array $body, string $variant): ?array
    {
        $allowed = $variant === 'ynng' ? ['yes', 'no', 'not given'] : ['true', 'false', 'not given'];
        $questions = [];
        foreach ($this->answered($g, $body) as $q) {
            $answer = strtolower(trim($q['answer']));
            if (! in_array($answer, $allowed, true)) {
                $this->error($q['_line'], 'The answer must be '.strtoupper(implode(', ', $allowed)).", not \"{$q['answer']}\".");
            }
            $questions[] = ['statement' => $q['prompt'], 'answer' => $answer];
        }

        return [...$data, 'variant' => $variant, 'questions' => $questions];
    }

    private function matching(array $g, array $data, array $body, string $variant): ?array
    {
        if (! $g['options']) {
            $this->error($g['line'], "Questions {$g['label']}: add an \"Options:\" line followed by the options, one per line (\"A) …\" or \"i) …\").");

            return null;
        }
        $count = count($g['options']);
        $keys = $variant === 'headings'
            ? array_slice(['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x', 'xi', 'xii', 'xiii', 'xiv', 'xv'], 0, $count)
            : array_map('strtolower', array_slice(range('A', 'Z'), 0, $count));
        $questions = array_map(fn ($q) => ['prompt' => $q['prompt'], 'answer' => $q['answer'], '_line' => $q['_line']], $this->answered($g, $body));
        foreach ($questions as $q) {
            foreach (explode('|', strtolower($q['answer'])) as $a) {
                if (! in_array(trim($a), $keys, true)) {
                    $this->error($q['_line'], "The answer \"{$q['answer']}\" is not one of the options (".implode(', ', $keys).').');
                }
            }
        }
        $reuse = in_array(strtolower((string) ($g['meta']['reuse']['value'] ?? '')), ['yes', 'true', '1'], true);

        return [...$data, 'variant' => $variant,
            'options_title' => $g['meta']['options title']['value'] ?? null,
            'allow_reuse' => $reuse,
            'options' => array_map(fn ($o) => $o['text'], $g['options']),
            'questions' => array_map(fn ($q) => ['prompt' => $q['prompt'], 'answer' => $q['answer']], $questions),
        ];
    }

    private function diagram(array $g, array $data, array $body, string $mode): ?array
    {
        $description = $g['meta']['image']['value'] ?? null;
        $body = array_values(array_filter($body, function ($l) use (&$description) {
            if (preg_match('/^\[image\s*:\s*(.+)\]$/i', trim($l['text']), $m)) {
                $description ??= trim($m[1]);

                return false;
            }

            return true;
        }));
        if (blank($description)) {
            $this->warn($g['line'], "Questions {$g['label']}: describe the picture with \"[image: …]\" (read aloud for students who cannot see it).");
        }
        $letters = (int) ($g['meta']['letters']['value'] ?? 8);
        $questions = [];
        foreach ($this->answered($g, $body, allowEmptyPrompt: $mode === 'words') as $q) {
            $x = $y = null;
            if (preg_match('/@\s*(\d+(?:\.\d+)?)\s*,\s*(\d+(?:\.\d+)?)\s*$/', $q['prompt'], $m)) {
                [$x, $y] = [(float) $m[1], (float) $m[2]];
                $q['prompt'] = trim(preg_replace('/@\s*[\d.]+\s*,\s*[\d.]+\s*$/', '', $q['prompt']));
            }
            if ($mode === 'letters') {
                $valid = array_slice(range('a', 'z'), 0, $letters);
                if (! in_array(strtolower(trim($q['answer'])), $valid, true)) {
                    $this->error($q['_line'], "The answer \"{$q['answer']}\" is not a letter on the map (A–".chr(64 + $letters).'). Add "Letters: N" if the map has more.');
                }
            }
            $questions[] = array_filter(['prompt' => $q['prompt'], 'answer' => $q['answer'], 'x' => $x, 'y' => $y], fn ($v) => $v !== null);
        }

        return [...$data, 'mode' => $mode, 'letters' => $letters, 'image' => null, 'image_alt' => (string) $description, 'questions' => $questions];
    }

    /**
     * Numbered lines ending in an answer: "14. The library opens at 9. [[FALSE]]".
     *
     * @return list<array{prompt: string, answer: string, _line: int}>
     */
    private function answered(array $g, array $body, bool $allowEmptyPrompt = false): array
    {
        $out = [];
        foreach ($body as $l) {
            $t = trim($l['text']);
            if (! preg_match('/^(\d+)\s*[.)]\s*(.*)$/u', $t, $m)) {
                if ($out) {
                    $out[count($out) - 1]['prompt'] .= ' '.$t; // a prompt running over two lines

                    continue;
                }
                $this->error($l['line'], 'Expected a numbered question here, e.g. "9. What time does the tour start? [[10.30]]".');

                continue;
            }
            $out[] = ['prompt' => $m[2], 'answer' => '', '_line' => $l['line']];
        }
        foreach ($out as &$q) {
            if (preg_match('/\[\[(.+?)\]\]/', $q['prompt'], $m)) {
                $q['answer'] = trim($m[1]);
                $q['prompt'] = trim(preg_replace('/\s*\[\[.+?\]\]\s*/', ' ', $q['prompt'], 1));
            } else {
                $this->error($q['_line'], 'No answer found. Write it in double brackets, e.g. [[NOT GIVEN]].');
            }
            if (! $allowEmptyPrompt && $q['prompt'] === '') {
                $this->warn($q['_line'], 'This question has no text.');
            }
        }

        return $out;
    }

    /* ---------- checks after compiling ---------- */

    private function checkNumbering(CompiledTest $compiled, array $refs): void
    {
        $key = $this->skill === Skill::Listening ? 'parts' : 'sections';
        $byId = [];
        foreach ($compiled->content[$key] as $container) {
            foreach ($container['groups'] as $group) {
                $byId[$group['id']] = $group;
            }
        }
        foreach ($refs as $ref) {
            $id = $compiled->form[$key][$ref['container']]['groups'][$ref['block']]['data']['id'] ?? null;
            $group = $byId[$id] ?? null;
            $g = $ref['group'];
            if ($group === null) {
                $this->error($g['line'], "Questions {$g['label']}: no questions were found in this block.");

                continue;
            }
            if ($group['firstNumber'] !== $g['first'] || $group['lastNumber'] !== $g['last']) {
                $found = $group['lastNumber'] - $group['firstNumber'] + 1;
                $expected = $g['last'] - $g['first'] + 1;
                $this->warn($g['line'], $found !== $expected
                    ? "Questions {$g['label']}: {$expected} expected, {$found} found."
                    : "Questions {$g['label']} come out as {$group['firstNumber']}–{$group['lastNumber']}: a block before this one has too many or too few questions.");
            }
        }
    }

    private function checkTotals(CompiledTest $compiled): void
    {
        $listening = $this->skill === Skill::Listening;
        $containers = $compiled->content[$listening ? 'parts' : 'sections'];
        $expected = $listening ? 4 : 3;
        if ($containers !== [] && count($containers) !== $expected) {
            $this->warn(null, ($listening ? 'Listening has 4 parts' : 'General Training Reading has 3 sections').', this test has '.count($containers).'.');
        }
        if ($containers !== [] && $compiled->questionCount !== 40) {
            $this->warn(null, "The test has {$compiled->questionCount} questions; a real test has 40.");
        }
    }

    /* ---------- helpers ---------- */

    private function error(?int $line, string $message): void
    {
        $this->errors[] = ['line' => $line, 'message' => $message];
    }

    private function warn(?int $line, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'message' => $message];
    }

    private function result(array $form, ?array $content, array $key, int $count, array $images): array
    {
        $sort = fn (array $list) => collect($list)->sortBy(fn ($p) => $p['line'] ?? 0)->values()->all();

        return [
            'form' => $form,
            'content' => $content,
            'answerKey' => $key,
            'questionCount' => $count,
            'errors' => $sort($this->errors),
            'warnings' => $sort($this->warnings),
            'images' => $images,
        ];
    }
}
