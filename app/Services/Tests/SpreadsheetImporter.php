<?php

namespace App\Services\Tests;

use App\Enums\Skill;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Turns the question spreadsheet (one row per question, see the template) into question blocks
 * for the test form. Columns:
 *
 *   Part | Type | Title | Instructions | Word limit | Question | Options | Answer | Picture
 *
 * Consecutive rows with the same Part and Type form one set of questions; a filled Title or
 * Instructions cell starts a new set. Gaps are written "____" and their answers go in Answer,
 * separated by ";" when a row has several gaps. Alternative answers use "|": "colour|color".
 */
class SpreadsheetImporter
{
    public const COLUMNS = ['Part', 'Type', 'Title', 'Instructions', 'Word limit', 'Question', 'Options', 'Answer', 'Picture'];

    private const GAP = '/_{3,}|…+|\.{4,}/u';

    /**
     * The Type column, in the words of what the student sees (the IELTS name in brackets).
     * These are the dropdown choices in the templates. Label → [block type, variant/layout, hint].
     */
    public const TYPE_LABELS = [
        'Fill gaps in notes (Note completion)' => ['gap_fill', 'notes', 'Notes with headings and bullet points, a box at each gap.'],
        'Fill gaps in a form (Form completion)' => ['gap_fill', 'form', 'A form (name, phone, date…) with a box at each gap.'],
        'Fill gaps in sentences (Sentence completion)' => ['gap_fill', 'sentences', 'Separate sentences, each with a gap.'],
        'Fill gaps in a summary (Summary completion)' => ['gap_fill', 'summary', 'One paragraph with gaps, optionally with a box of words to choose from.'],
        'Fill gaps in a flow chart (Flow-chart completion)' => ['gap_fill', 'flow_chart', 'Steps one under the other, with gaps.'],
        'Fill gaps in a table (Table completion)' => ['table_completion', null, 'A table with gaps in some cells.'],
        'Choose A, B or C (Multiple choice)' => ['multiple_choice', null, 'A question with options A, B, C (D…). Two letters in Answer makes "Choose TWO".'],
        'Choose TWO letters (Multiple choice)' => ['multiple_choice', null, 'Choose two answers from a list of options (A–E). Write both letters in Answer.'],
        'True / False / Not given' => ['true_false_not_given', 'tfng', 'A statement with three buttons: TRUE, FALSE, NOT GIVEN.'],
        'Yes / No / Not given' => ['true_false_not_given', 'ynng', 'The writer\'s opinion: YES, NO, NOT GIVEN.'],
        'Match each item to a list (Matching)' => ['matching', 'general', 'A list of options in a box and a dropdown next to each question.'],
        'Choose a heading for each paragraph (Matching headings)' => ['matching', 'headings', 'A list of headings i, ii, iii… and a dropdown for each paragraph.'],
        'Which paragraph contains… (Matching information)' => ['matching', 'information', 'Pick the paragraph letter (A, B, C…) that contains each piece of information.'],
        'Match people or things to statements (Matching features)' => ['matching', 'features', 'A list of people, places or things and a dropdown next to each statement.'],
        'Finish each sentence from a list (Sentence endings)' => ['matching', 'sentence_endings', 'Sentence beginnings, and a list of endings to choose from.'],
        'Label a map (Map labelling)' => ['diagram_label', 'letters', 'A map with letters; the student picks the letter for each place.'],
        'Label a diagram (Diagram labelling)' => ['diagram_label', 'words', 'A picture of a machine or process; the student types the missing labels.'],
        'Answer with a few words (Short answer)' => ['short_answer', null, 'A question with a box for a short written answer.'],
    ];

    /** Older short names, still accepted (normalised: lower case, letters and digits only). */
    private const TYPE_ALIASES = [
        'multiple choice' => ['multiple_choice', null],
        'true false not given' => ['true_false_not_given', 'tfng'],
        'tfng' => ['true_false_not_given', 'tfng'],
        'yes no not given' => ['true_false_not_given', 'ynng'],
        'ynng' => ['true_false_not_given', 'ynng'],
        'matching' => ['matching', 'general'],
        'matching headings' => ['matching', 'headings'],
        'matching information' => ['matching', 'information'],
        'matching features' => ['matching', 'features'],
        'sentence endings' => ['matching', 'sentence_endings'],
        'notes' => ['gap_fill', 'notes'],
        'note completion' => ['gap_fill', 'notes'],
        'form' => ['gap_fill', 'form'],
        'form completion' => ['gap_fill', 'form'],
        'sentences' => ['gap_fill', 'sentences'],
        'sentence completion' => ['gap_fill', 'sentences'],
        'summary' => ['gap_fill', 'summary'],
        'summary completion' => ['gap_fill', 'summary'],
        'flow chart' => ['gap_fill', 'flow_chart'],
        'flow chart completion' => ['gap_fill', 'flow_chart'],
        'table' => ['table_completion', null],
        'table completion' => ['table_completion', null],
        'map' => ['diagram_label', 'letters'],
        'map labelling' => ['diagram_label', 'letters'],
        'diagram' => ['diagram_label', 'words'],
        'diagram labelling' => ['diagram_label', 'words'],
        'short answer' => ['short_answer', null],
    ];

    /** Which columns each block type uses (the others are greyed out in the template examples). */
    public const USED_COLUMNS = [
        'gap_fill' => ['Title', 'Instructions', 'Word limit', 'Question', 'Options', 'Answer', 'Picture'],
        'table_completion' => ['Title', 'Instructions', 'Word limit', 'Question', 'Answer', 'Picture'],
        'multiple_choice' => ['Instructions', 'Question', 'Options', 'Answer', 'Picture'],
        'true_false_not_given' => ['Instructions', 'Question', 'Answer', 'Picture'],
        'matching' => ['Title', 'Instructions', 'Question', 'Options', 'Answer', 'Picture'],
        'diagram_label' => ['Title', 'Instructions', 'Word limit', 'Question', 'Options', 'Answer', 'Picture'],
        'short_answer' => ['Instructions', 'Word limit', 'Question', 'Answer', 'Picture'],
    ];

    /** @return array{0: string, 1: ?string}|null [block type, variant] for a Type cell. */
    public static function typeOf(string $cell): ?array
    {
        $key = self::key($cell);
        if ($key === '') {
            return null;
        }
        foreach (self::TYPE_LABELS as $label => [$type, $variant]) {
            if (self::key($label) === $key) {
                return [$type, $variant];
            }
        }
        if (isset(self::TYPE_ALIASES[$key])) {
            return self::TYPE_ALIASES[$key];
        }
        // "Anything (Note completion)": the IELTS name in brackets is enough.
        if (preg_match('/\(([^)]*)\)\s*$/', $cell, $m) && isset(self::TYPE_ALIASES[self::key($m[1])])) {
            return self::TYPE_ALIASES[self::key($m[1])];
        }

        return null;
    }

    private static function key(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }

    /**
     * The question rows: the sheet called "Questions" if there is one (the full-test workbook),
     * otherwise the first sheet (a CSV, or the question template).
     *
     * @return list<array{row: int, cells: array<string, string>}>
     */
    public function read(string $path, ?string $extension = null): array
    {
        $sheets = $this->readSheets($path, $extension, ['questions' => self::COLUMNS]);

        return $sheets['questions'] ?? $sheets[0] ?? [];
    }

    /**
     * Reads every sheet. Sheets named in $columns (lower case name → column headings) come back
     * under that name with their cells keyed by heading; the first sheet is also returned as [0]
     * with $columns's first entry's headings.
     *
     * @param  array<string, list<string>>  $columns
     * @return array<int|string, list<array{row: int, cells: array<string, string>}>>
     */
    public function readSheets(string $path, ?string $extension, array $columns): array
    {
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));
        $reader = match ($extension) {
            'xlsx' => new XlsxReader,
            'ods' => new OdsReader,
            default => new CsvReader($this->csvOptions($path)),
        };
        $reader->open($path);
        $result = [];
        try {
            foreach ($reader->getSheetIterator() as $index => $sheet) {
                $name = strtolower(trim($sheet->getName()));
                $wanted = $columns[$name] ?? ($index === 1 ? reset($columns) : null);
                if ($wanted === null) {
                    continue;
                }
                $rows = [];
                $header = null;
                foreach ($sheet->getRowIterator() as $n => $row) {
                    $values = array_map(fn ($v) => self::cellText($v), $row->toArray());
                    if ($header === null) {
                        $header = array_map(fn ($h) => strtolower(trim(str_replace("\u{FEFF}", '', $h))), $values);

                        continue;
                    }
                    $cells = [];
                    foreach ($wanted as $col) {
                        $i = array_search(strtolower($col), $header, true);
                        $cells[$col] = $i === false ? '' : ($values[$i] ?? '');
                    }
                    if (implode('', $cells) !== '') {
                        $rows[] = ['row' => $n, 'cells' => $cells];
                    }
                }
                if (isset($columns[$name])) {
                    $result[$name] = $rows;
                }
                if ($index === 1) {
                    $result[0] = $rows;
                }
            }
        } finally {
            $reader->close();
        }

        return $result;
    }

    /** Cell value as typed. Excel turns "7:42" into a time of day: give it back as "7:42". */
    private static function cellText(mixed $v): string
    {
        if ($v instanceof \DateTimeInterface) {
            $h = (int) $v->format('G');
            $s = (int) $v->format('s');

            return $s === 0 ? $h.':'.$v->format('i') : $h.':'.$v->format('i:s');
        }
        if ($v instanceof \DateInterval) {
            $h = $v->d * 24 + $v->h;

            return $v->s === 0 ? $h.':'.sprintf('%02d', $v->i) : $h.':'.sprintf('%02d:%02d', $v->i, $v->s);
        }
        if (is_float($v) && floor($v) === $v) {
            return (string) (int) $v;
        }

        return trim((string) $v);
    }

    /**
     * @param  list<array{row: int, cells: array<string, string>}>  $rows
     * @param  array<string, string>  $pictures  file name (lower case) → stored path
     * @return array{containers: array<int, list<array{type: string, data: array}>>, errors: list<array{row: int, message: string}>, pictures: list<string>, count: int}
     */
    public function parse(Skill $skill, array $rows, array $pictures = []): array
    {
        $max = $skill === Skill::Listening ? 4 : 3;
        $unit = $skill === Skill::Listening ? 'Part' : 'Section';
        $errors = [];
        $sets = [];

        foreach ($rows as ['row' => $n, 'cells' => $c]) {
            $type = self::typeOf($c['Type']);
            $part = (int) $c['Part'];
            if ($part < 1 || $part > $max) {
                $errors[] = ['row' => $n, 'message' => "{$unit} must be a number from 1 to {$max}."];

                continue;
            }
            if (! $type) {
                $errors[] = ['row' => $n, 'message' => $c['Type'] === '' ? 'Type is empty.' : "Unknown type \"{$c['Type']}\". Choose one from the dropdown (see the \"Question types\" sheet)."];

                continue;
            }
            $last = $sets ? array_key_last($sets) : null;
            $startsNew = $last === null || $sets[$last]['part'] !== $part || $sets[$last]['key'] !== $type
                || $c['Title'] !== '' || $c['Instructions'] !== '';
            if ($startsNew) {
                $sets[] = ['part' => $part, 'key' => $type, 'rows' => []];
                $last = array_key_last($sets);
            }
            $sets[$last]['rows'][] = ['row' => $n, 'cells' => $c];
        }

        $containers = [];
        $wanted = [];
        $count = 0;
        foreach ($sets as $set) {
            [$block, $setErrors, $questions] = $this->block($set['key'][0], $set['key'][1], $set['rows'], $pictures, $wanted);
            $errors = [...$errors, ...$setErrors];
            $count += $questions;
            $containers[$set['part'] - 1][] = $block;
        }
        usort($errors, fn ($a, $b) => $a['row'] <=> $b['row']);

        return ['containers' => $containers, 'errors' => $errors, 'pictures' => array_values(array_unique($wanted)), 'count' => $count];
    }

    /** @return array{0: array, 1: list<array>, 2: int} block, errors, number of questions */
    private function block(string $type, ?string $variant, array $rows, array $pictures, array &$wanted): array
    {
        $first = $rows[0]['cells'];
        $errors = [];
        $err = function (int $row, string $message) use (&$errors) {
            $errors[] = ['row' => $row, 'message' => $message];
        };
        $data = array_filter([
            'instructions' => $first['Instructions'] ?: null,
            'word_limit' => $first['Word limit'] ?: null,
        ]);

        // A picture anywhere in the set; for maps and diagrams it is the image itself.
        $pictureRow = collect($rows)->first(fn ($r) => $r['cells']['Picture'] !== '');
        $picture = null;
        if ($pictureRow) {
            $name = $pictureRow['cells']['Picture'];
            $wanted[] = $name;
            $picture = $pictures[strtolower(basename($name))] ?? null;
            if (! $picture) {
                $err($pictureRow['row'], "Picture \"{$name}\" was not uploaded with the spreadsheet.");
            }
        }
        $optionsCell = collect($rows)->map(fn ($r) => $r['cells']['Options'])->first(fn ($o) => $o !== '') ?? '';
        $options = $this->split($optionsCell);
        $questions = 0;

        switch ($type) {
            case 'multiple_choice':
                $data['questions'] = [];
                foreach ($rows as ['row' => $n, 'cells' => $c]) {
                    $opts = $this->split($c['Options']);
                    $answers = $this->letters($c['Answer']);
                    if (count($opts) < 2) {
                        $err($n, 'Multiple choice needs at least two options in Options, separated by "|".');
                    }
                    if (! $answers) {
                        $err($n, 'Write the correct letter in Answer, e.g. B (or B, D for "Choose TWO").');
                    }
                    foreach ($answers as $a) {
                        if (ord($a) - 65 >= count($opts)) {
                            $err($n, "Answer {$a} is not one of the options.");
                        }
                    }
                    $data['questions'][] = [
                        'prompt' => $c['Question'],
                        'options' => array_map(fn ($o, $i) => ['text' => $o, 'correct' => in_array(chr(65 + $i), $answers, true)], $opts, array_keys($opts)),
                    ];
                    $questions += max(1, count($answers));
                }
                break;

            case 'true_false_not_given':
                $allowed = $variant === 'ynng' ? ['yes', 'no', 'not given'] : ['true', 'false', 'not given'];
                $data['variant'] = $variant;
                $data['questions'] = [];
                foreach ($rows as ['row' => $n, 'cells' => $c]) {
                    $answer = $this->normalise($c['Answer']);
                    $answer = ['t' => 'true', 'f' => 'false', 'y' => 'yes', 'n' => 'no', 'ng' => 'not given'][$answer] ?? $answer;
                    if (! in_array($answer, $allowed, true)) {
                        $err($n, 'Answer must be '.strtoupper(implode(', ', $allowed)).'.');
                    }
                    $data['questions'][] = ['statement' => $c['Question'], 'answer' => $answer];
                    $questions++;
                }
                break;

            case 'matching':
                $data['variant'] = $variant;
                $data['options_title'] = $first['Title'] ?: null;
                $data['options'] = $options;
                $data['questions'] = [];
                if (count($options) < 2) {
                    $err($rows[0]['row'], 'Matching needs its list of options in Options, separated by "|".');
                }
                $keys = $variant === 'headings' ? ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x', 'xi', 'xii'] : range('A', 'Z');
                $keys = array_slice($keys, 0, count($options));
                $answerKeys = [];
                foreach ($rows as ['row' => $n, 'cells' => $c]) {
                    $answer = trim($c['Answer']);
                    if ($answer === '' || ($options && ! in_array(strtolower($answer), array_map('strtolower', $keys), true))) {
                        $err($n, 'Answer must be one of the option letters'.($keys ? ' ('.implode(', ', $keys).').' : '.'));
                    }
                    $answerKeys[] = strtolower($answer);
                    $data['questions'][] = ['prompt' => $c['Question'], 'answer' => $answer];
                    $questions++;
                }
                $data['allow_reuse'] = count($answerKeys) !== count(array_unique($answerKeys));
                break;

            case 'gap_fill':
                $data['layout'] = $variant;
                $data['title'] = $first['Title'] ?: null;
                if ($options) {
                    $data['word_bank'] = $options;
                }
                $lines = [];
                foreach ($rows as ['row' => $n, 'cells' => $c]) {
                    [$line, $gaps] = $this->fillGaps($c['Question'], $c['Answer'], $n, $err);
                    $lines[] = $line;
                    $questions += $gaps;
                }
                $data['text'] = implode("\n", $lines);
                break;

            case 'table_completion':
                $data['title'] = $first['Title'] ?: null;
                $data['headers'] = $first['Question'];
                $data['rows'] = [];
                if (count($rows) < 2) {
                    $err($rows[0]['row'], 'A table needs a first row with the column headings, then one row per table row.');
                }
                foreach (array_slice($rows, 1) as ['row' => $n, 'cells' => $c]) {
                    [$line, $gaps] = $this->fillGaps($c['Question'], $c['Answer'], $n, $err);
                    $data['rows'][] = $line;
                    $questions += $gaps;
                }
                break;

            case 'diagram_label':
                $data['mode'] = $variant;
                $data['title'] = $first['Title'] ?: null;
                $data['image'] = $picture;
                $data['image_alt'] = $first['Title'] ?: ($variant === 'letters' ? 'Map' : 'Diagram');
                if (! $pictureRow) {
                    $err($rows[0]['row'], 'A '.($variant === 'letters' ? 'map' : 'diagram').' needs its picture: write the file name in Picture.');
                }
                if ($variant === 'letters') {
                    $data['letters'] = $this->letterCount($optionsCell) ?? 8;
                }
                $data['questions'] = [];
                foreach ($rows as ['row' => $n, 'cells' => $c]) {
                    if (trim($c['Answer']) === '') {
                        $err($n, 'Answer is empty.');
                    }
                    $data['questions'][] = ['prompt' => $c['Question'], 'answer' => trim($c['Answer'])];
                    $questions++;
                }
                $picture = null; // already used as the image
                break;

            case 'short_answer':
                $data['questions'] = [];
                foreach ($rows as ['row' => $n, 'cells' => $c]) {
                    if (trim($c['Answer']) === '') {
                        $err($n, 'Answer is empty.');
                    }
                    $data['questions'][] = ['prompt' => $c['Question'], 'answer' => trim($c['Answer'])];
                    $questions++;
                }
                break;
        }

        if ($picture) {
            $data['picture'] = $picture;
            $data['picture_alt'] = $first['Title'] ?: 'Picture for these questions';
        }

        return [['type' => $type, 'data' => $data], $errors, $questions];
    }

    /** "Name: ____ Smith" + "John" → "Name: [[John]] Smith". */
    private function fillGaps(string $text, string $answerCell, int $row, \Closure $err): array
    {
        $answers = $answerCell === '' ? [] : array_map('trim', explode(';', $answerCell));
        $gaps = preg_match_all(self::GAP, $text);
        if ($gaps !== count($answers)) {
            $err($row, $gaps === 0 && $answers
                ? 'Answer is filled but Question has no gap. Mark the gap with ____.'
                : "Question has {$gaps} gap(s) but Answer has ".count($answers).'. Separate several answers with ";".');
        }
        $i = 0;
        $line = preg_replace_callback(self::GAP, function () use (&$i, $answers) {
            return '[['.($answers[$i++] ?? '').']]';
        }, $text);

        return [$line, $gaps];
    }

    /** "A | B | C" or one per line. */
    private function split(string $cell): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\||\R/', $cell)), fn ($o) => $o !== ''));
    }

    /** "B", "B, D", "b and d" → ['B', 'D']. */
    private function letters(string $cell): array
    {
        preg_match_all('/\b([A-Za-z])\b/', $cell, $m);

        return array_values(array_unique(array_map('strtoupper', $m[1])));
    }

    /** "A-H" → 8, "8" → 8. */
    private function letterCount(string $cell): ?int
    {
        if (preg_match('/^\s*([A-Z])\s*[-–]\s*([A-Z])\s*$/i', $cell, $m)) {
            return ord(strtoupper($m[2])) - ord(strtoupper($m[1])) + 1;
        }

        return ctype_digit(trim($cell)) ? (int) trim($cell) : null;
    }

    private function normalise(string $value): string
    {
        return trim(preg_replace('/[\s_\/-]+/', ' ', strtolower($value)));
    }

    private function csvOptions(string $path): CsvOptions
    {
        // Excel in French/Arabic locales saves CSV with ";".
        $firstLine = (string) fgets(fopen($path, 'r'));
        $options = new CsvOptions;
        $options->FIELD_DELIMITER = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        return $options;
    }
}
