<?php

namespace App\Services\Tests;

use App\Enums\Skill;
use App\Models\Test;
use App\Services\Audio\RecordingSplitter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * A whole test in one Excel workbook, so the teacher never has to fill the test form field by field.
 *
 *   Details    Field | Value | Help              title, skill, free/published, recordings…
 *   Passages   Section | Passage title | Subtitle | Paragraph letter | Text | Picture    (Reading)
 *   Questions  as SpreadsheetImporter::COLUMNS                                            (Listening, Reading)
 *   Writing    Task | Situation and question | Bullet points | Closing line | Minimum words
 *   Speaking   Part | What | Text      What = Topic, Question, Point, Closing line, Follow-up question
 *
 * parse() turns it into the same data the test form saves (skill, title… and `form`), so the Test
 * model compiles it exactly like a test typed in by hand. Media (recordings, pictures) are uploaded
 * next to the workbook and matched by file name.
 */
class TestWorkbook
{
    public const DETAILS = ['Field', 'Value', 'Help'];

    public const PASSAGES = ['Section', 'Passage title', 'Subtitle', 'Paragraph letter', 'Text', 'Picture'];

    public const WRITING = ['Task', 'Situation and question', 'Bullet points', 'Closing line', 'Minimum words'];

    public const SPEAKING = ['Part', 'What', 'Text'];

    private const SPEAKING_WHAT = ['Topic', 'Question', 'Point', 'Closing line', 'Follow-up question'];

    private const MINUTES = ['listening' => 40, 'reading' => 60, 'writing' => 60, 'speaking' => 14];

    public function __construct(private SpreadsheetImporter $questions) {}

    /* ---------- the template ---------- */

    public function writeTemplate(string $path, Skill $skill): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $dropdowns = [];
        $sheetNo = 1;

        // Details
        $sheet = $writer->getCurrentSheet()->setName('Details');
        $sheet->setColumnWidth(24, 1);
        $sheet->setColumnWidth(40, 2);
        $sheet->setColumnWidth(80, 3);
        $writer->addRow(Row::fromValues(self::DETAILS, QuestionTemplate::bold()));
        $text = (new Style)->setFormat('@'); // keep "7:42" as typed, not a time of day
        $detailRows = $this->detailRows($skill);
        $line = 2;
        $details = [];
        foreach ($detailRows as [$field, $value, $help]) {
            $writer->addRow(Row::fromValuesWithStyles([$field, $value, $help], null, [1 => $text, 2 => QuestionTemplate::wrap()]));
            if ($field === 'Skill') {
                $details[] = ["B{$line}", '"listening,reading,writing,speaking"'];
            } elseif (in_array($field, ['Free test', 'Published'], true)) {
                $details[] = ["B{$line}", '"yes,no"'];
            }
            $line++;
        }
        $dropdowns[$sheetNo++] = $details;

        if ($skill === Skill::Reading) {
            $sheet = $writer->addNewSheetAndMakeItCurrent()->setName('Passages');
            $sheet->setColumnWidth(9, 1);
            $sheet->setColumnWidth(32, 2, 3);
            $sheet->setColumnWidth(10, 4);
            $sheet->setColumnWidth(90, 5);
            $sheet->setColumnWidth(22, 6);
            $sheet->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues(self::PASSAGES, QuestionTemplate::bold()));
            foreach (self::READING_PASSAGES as $row) {
                $writer->addRow(Row::fromValuesWithStyles($row, null, [4 => QuestionTemplate::wrap()]));
            }
            $sheetNo++;
        }

        if ($skill === Skill::Listening || $skill === Skill::Reading) {
            QuestionTemplate::questionsSheet($writer->addNewSheetAndMakeItCurrent(), $writer,
                $skill === Skill::Listening ? QuestionTemplate::LISTENING_EXAMPLES : self::READING_QUESTIONS,
                $skill === Skill::Listening ? 'The part, 1 to 4.' : 'The section, 1 to 3.');
            $dropdowns[$sheetNo++] = [QuestionTemplate::typeDropdown('B2:B1000')];
            QuestionTemplate::typesSheet($writer->addNewSheetAndMakeItCurrent(), $writer);
            $sheetNo++;
        }

        if ($skill === Skill::Writing) {
            $sheet = $writer->addNewSheetAndMakeItCurrent()->setName('Writing');
            $sheet->setColumnWidth(7, 1);
            $sheet->setColumnWidth(70, 2);
            $sheet->setColumnWidth(45, 3);
            $sheet->setColumnWidth(45, 4);
            $sheet->setColumnWidth(14, 5);
            $writer->addRow(Row::fromValues(self::WRITING, QuestionTemplate::bold()));
            foreach (self::WRITING_EXAMPLES as $row) {
                $writer->addRow(Row::fromValues($row, QuestionTemplate::wrap()));
            }
            $sheetNo++;
        }

        if ($skill === Skill::Speaking) {
            $sheet = $writer->addNewSheetAndMakeItCurrent()->setName('Speaking');
            $sheet->setColumnWidth(7, 1);
            $sheet->setColumnWidth(22, 2);
            $sheet->setColumnWidth(80, 3);
            $sheet->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues(self::SPEAKING, QuestionTemplate::bold()));
            foreach (self::SPEAKING_EXAMPLES as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            $dropdowns[$sheetNo++] = [['B2:B500', '"'.implode(',', self::SPEAKING_WHAT).'"']];
        }

        QuestionTemplate::helpSheet($writer->addNewSheetAndMakeItCurrent(), $writer, [
            'One workbook = one '.$skill->value.' test. Replace the examples with your own content, keep the first row of every sheet (the column names).',
            'Then in the admin: Tests → "New test from spreadsheet", upload this file with its '.($skill === Skill::Listening ? 'recording(s) and pictures' : 'pictures').', check the preview and create.',
            'Leave Published on "no" until everything is in: the test is saved as a draft and you can finish it later.',
        ], $this->helpSections($skill));

        $writer->close();
        QuestionTemplate::addDropdowns($path, $dropdowns);
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function detailRows(Skill $skill): array
    {
        $rows = [
            ['Title', 'Practice Test 1', 'The name students see in the test library.'],
            ['Skill', $skill->value, 'listening, reading, writing or speaking.'],
            ['Description', '', 'Optional. A short summary for the library, e.g. the topics.'],
            ['Time allowed (minutes)', (string) self::MINUTES[$skill->value], 'Leave as it is for the real exam timing.'],
            ['Free test', 'no', 'yes = available on the free plan.'],
            ['Published', 'no', 'yes = students can see it. With missing parts it is saved as a draft anyway.'],
            ['Sort order', '0', 'Lower numbers appear first in the library.'],
        ];
        if ($skill === Skill::Listening) {
            $rows = [...$rows,
                ['Full recording', 'test1-full.mp3', 'File name of the recording of all 4 parts. Upload it with the workbook. Leave empty if you have one file per part (below).'],
                ['Part 2 starts', '7:42', 'Where Part 2 starts in the full recording, minutes:seconds.'],
                ['Part 3 starts', '15:10', 'minutes:seconds'],
                ['Part 4 starts', '23:05', 'minutes:seconds'],
                ['Part 1 recording', '', 'Only if you have one file per part instead of the full recording: its file name.'],
                ['Part 2 recording', '', ''],
                ['Part 3 recording', '', ''],
                ['Part 4 recording', '', ''],
            ];
        }

        return $rows;
    }

    /** @return array<string, list<array{0: string, 1: string}>> */
    private function helpSections(Skill $skill): array
    {
        return match ($skill) {
            Skill::Reading => ['Passages sheet' => [
                ['Section', 'The section, 1 to 3.'],
                ['Passage title', 'Write the title on the first paragraph of each text. A new title starts a new text.'],
                ['Subtitle', 'Optional, on the same row as the title.'],
                ['Paragraph letter', 'Optional: A, B, C… when questions refer to paragraphs.'],
                ['Text', 'One paragraph per row.'],
                ['Picture', 'Instead of typing: the file name of a photo or scan of the paragraph (or of a whole page). Upload it with the workbook. Typed text lets students highlight.'],
            ]],
            Skill::Writing => ['Writing sheet' => [
                ['Task', '1 (the letter) or 2 (the essay). A test can have one task or both.'],
                ['Situation and question', 'The task as students read it. Leave an empty line (Alt+Enter twice) between paragraphs.'],
                ['Bullet points', 'Task 1: the "In your letter" points, one per line (Alt+Enter) or separated by |.'],
                ['Closing line', 'Optional, e.g. Begin your letter as follows: Dear Sir or Madam,'],
                ['Minimum words', 'Optional: 150 for Task 1, 250 for Task 2 if empty.'],
            ]],
            Skill::Speaking => ['Speaking sheet' => [
                ['Part', '1 (interview), 2 (cue card) or 3 (discussion).'],
                ['What', 'Pick from the dropdown. Parts 1 and 3: one Topic row, then one Question row per question. Part 2: Topic = the cue card ("Describe…"), Point = each "You should say" line, Closing line = the last line, Follow-up question = asked after the talk.'],
                ['Text', 'The words students see.'],
            ]],
            Skill::Listening => ['Details sheet (recordings)' => [
                ['Full recording', 'Most books have one recording for the whole test: write its file name and where Parts 2, 3 and 4 start. It is cut into the 4 parts automatically.'],
                ['One file per part', 'Leave Full recording empty and write the 4 file names in Part 1–4 recording instead.'],
            ]],
        };
    }

    /* ---------- reading the workbook ---------- */

    /** @return array<string, list<array{row: int, cells: array<string, string>}>> */
    public function read(string $path, ?string $extension = null): array
    {
        return $this->questions->readSheets($path, $extension, [
            'details' => self::DETAILS,
            'passages' => self::PASSAGES,
            'questions' => SpreadsheetImporter::COLUMNS,
            'writing' => self::WRITING,
            'speaking' => self::SPEAKING,
        ]);
    }

    /**
     * @param  array<string, list<array{row: int, cells: array<string, string>}>>  $sheets  from read()
     * @param  array<string, string>  $media  file name (lower case) → stored path on the public disk
     * @return array{data: ?array, skill: ?Skill, errors: list<array{sheet: string, row: int, message: string}>, content: ?array, questionCount: int, problems: list<string>, files: list<string>}
     */
    public function parse(array $sheets, array $media = []): array
    {
        $errors = [];
        $files = [];
        $err = function (string $sheet, int $row, string $message) use (&$errors) {
            $errors[] = ['sheet' => $sheet, 'row' => $row, 'message' => $message];
        };
        $empty = ['data' => null, 'skill' => null, 'content' => null, 'questionCount' => 0, 'problems' => [], 'files' => []];

        if (! isset($sheets['details'])) {
            return [...$empty, 'errors' => [['sheet' => 'Details', 'row' => 1, 'message' => 'This is not a Bandora test workbook: the Details sheet is missing. Download the template and fill it in.']]];
        }
        $details = [];
        foreach ($sheets['details'] as ['row' => $n, 'cells' => $c]) {
            $details[$this->key($c['Field'])] = ['value' => $c['Value'], 'row' => $n];
        }
        $get = fn (string $field) => $details[$this->key($field)]['value'] ?? '';
        $rowOf = fn (string $field) => $details[$this->key($field)]['row'] ?? 1;
        $file = function (string $sheet, int $row, string $name) use ($media, &$files, $err): ?string {
            $files[] = $name;
            $path = $media[strtolower(basename(trim($name)))] ?? null;
            if (! $path) {
                $err($sheet, $row, "\"{$name}\" was not uploaded with the workbook.");
            }

            return $path;
        };

        $skill = Skill::tryFrom(strtolower(trim($get('Skill'))));
        if (! $skill) {
            $err('Details', $rowOf('Skill'), 'Skill must be listening, reading, writing or speaking.');

            return [...$empty, 'errors' => $errors];
        }
        if (trim($get('Title')) === '') {
            $err('Details', $rowOf('Title'), 'Write the title of the test.');
        }
        $minutes = $get('Time allowed (minutes)');
        if ($minutes !== '' && (! is_numeric($minutes) || (float) $minutes < 1)) {
            $err('Details', $rowOf('Time allowed (minutes)'), 'Time allowed must be a number of minutes, e.g. 40.');
        }

        $form = match ($skill) {
            Skill::Listening => $this->listening($sheets, $media, $get, $rowOf, $file, $err),
            Skill::Reading => $this->reading($sheets, $media, $file, $err),
            Skill::Writing => $this->writing($sheets, $err),
            Skill::Speaking => $this->speaking($sheets, $err),
        };

        $data = [
            'skill' => $skill->value,
            'title' => trim($get('Title')),
            'description' => trim($get('Description')) ?: null,
            'duration_seconds' => (int) round(60 * (is_numeric($minutes) && (float) $minutes >= 1 ? (float) $minutes : self::MINUTES[$skill->value])),
            'sort' => (int) $get('Sort order'),
            'is_free' => $this->yes($get('Free test')),
            'is_published' => $this->yes($get('Published')),
            'form' => $form,
        ];

        // Preview: what the test will look like once compiled (recordings still to cut count as there).
        $probe = $form;
        if ($skill === Skill::Listening && filled($form['recording']['file'] ?? null)) {
            foreach (range(0, 3) as $i) {
                $probe['parts'][$i] = ($probe['parts'][$i] ?? ['groups' => []]) + ['audio' => $form['recording']['file']];
            }
            ksort($probe['parts']);
        }
        $compiled = app(TestCompiler::class)->compile($skill, $probe);
        usort($errors, fn ($a, $b) => [$this->sheetOrder($a['sheet']), $a['row']] <=> [$this->sheetOrder($b['sheet']), $b['row']]);

        return [
            'data' => $data,
            'skill' => $skill,
            'errors' => $errors,
            'content' => $compiled->content,
            'questionCount' => $compiled->questionCount,
            'problems' => Test::problemsFor($skill, $probe),
            'files' => array_values(array_unique($files)),
        ];
    }

    private function listening(array $sheets, array $media, \Closure $get, \Closure $rowOf, \Closure $file, \Closure $err): array
    {
        $result = $this->questions->parse(Skill::Listening, $sheets['questions'] ?? [], $media);
        foreach ($result['errors'] as $e) {
            $err('Questions', $e['row'], $e['message']);
        }
        $parts = [];
        foreach ($result['containers'] as $i => $blocks) {
            $parts[$i] = ['groups' => $blocks];
        }
        $form = [];

        $full = trim($get('Full recording'));
        if ($full !== '') {
            $recording = ['file' => $file('Details', $rowOf('Full recording'), $full)];
            $previous = 0.0;
            foreach ([2, 3, 4] as $n) {
                $value = trim($get("Part {$n} starts"));
                $seconds = RecordingSplitter::seconds($value);
                if ($seconds === null || $seconds <= $previous) {
                    $err('Details', $rowOf("Part {$n} starts"), $value === ''
                        ? "Write where Part {$n} starts in the full recording, e.g. 7:42."
                        : "Part {$n} starts must be minutes:seconds (e.g. 7:42), after the start of Part ".($n - 1).'.');
                }
                $previous = $seconds ?? $previous;
                $recording["part{$n}"] = $value;
            }
            if ($recording['file']) {
                $form['recording'] = $recording;
            }
        } else {
            foreach (range(1, 4) as $n) {
                $name = trim($get("Part {$n} recording"));
                if ($name !== '' && ($path = $file('Details', $rowOf("Part {$n} recording"), $name))) {
                    $parts[$n - 1] = ($parts[$n - 1] ?? ['groups' => []]) + ['audio' => $path];
                }
            }
        }

        $form['parts'] = $this->fillGaps($parts, ['groups' => []]);

        return $form;
    }

    private function reading(array $sheets, array $media, \Closure $file, \Closure $err): array
    {
        $result = $this->questions->parse(Skill::Reading, $sheets['questions'] ?? [], $media);
        foreach ($result['errors'] as $e) {
            $err('Questions', $e['row'], $e['message']);
        }
        $sections = [];
        foreach ($result['containers'] as $i => $blocks) {
            $sections[$i] = ['passages' => [], 'groups' => $blocks];
        }

        $current = null; // [section index, passage index]
        foreach ($sheets['passages'] ?? [] as ['row' => $n, 'cells' => $c]) {
            $section = (int) $c['Section'];
            if ($section < 1 || $section > 3) {
                $err('Passages', $n, 'Section must be a number from 1 to 3.');

                continue;
            }
            $s = $section - 1;
            $sections[$s] ??= ['passages' => [], 'groups' => []];
            if ($c['Passage title'] !== '' || $current === null || $current[0] !== $s) {
                if ($c['Passage title'] === '') {
                    $err('Passages', $n, 'Write the title of the text on its first paragraph.');
                }
                $sections[$s]['passages'][] = ['title' => $c['Passage title'], 'subtitle' => $c['Subtitle'] ?: null, 'paragraphs' => []];
                $current = [$s, array_key_last($sections[$s]['passages'])];
            }
            $paragraph = ['label' => $c['Paragraph letter'] ?: null, 'text' => $c['Text']];
            if ($c['Picture'] !== '') {
                $paragraph['image'] = $file('Passages', $n, $c['Picture']);
                $paragraph['image_alt'] = trim(($sections[$s]['passages'][$current[1]]['title'] ?? '').' '.($c['Paragraph letter'] ? 'paragraph '.$c['Paragraph letter'] : ''));
            }
            if ($c['Text'] === '' && $c['Picture'] === '') {
                $err('Passages', $n, 'Type the paragraph in Text, or write the file name of a picture of it in Picture.');
            }
            $sections[$s]['passages'][$current[1]]['paragraphs'][] = $paragraph;
        }

        return ['sections' => $this->fillGaps($sections, ['passages' => [], 'groups' => []])];
    }

    private function writing(array $sheets, \Closure $err): array
    {
        $tasks = [];
        foreach ($sheets['writing'] ?? [] as ['row' => $n, 'cells' => $c]) {
            $number = (int) $c['Task'];
            if (! in_array($number, [1, 2], true)) {
                $err('Writing', $n, 'Task must be 1 (the letter) or 2 (the essay).');

                continue;
            }
            if (isset($tasks[$number])) {
                $err('Writing', $n, "Task {$number} is written twice.");

                continue;
            }
            if (trim($c['Situation and question']) === '') {
                $err('Writing', $n, 'Write the task in "Situation and question".');
            }
            if ($c['Minimum words'] !== '' && ! ctype_digit($c['Minimum words'])) {
                $err('Writing', $n, 'Minimum words must be a number, e.g. 150.');
            }
            $tasks[$number] = [
                'number' => $number,
                'kind' => $number === 1 ? 'letter' : 'essay',
                'prompt' => $c['Situation and question'],
                'bullets' => implode("\n", $this->lines($c['Bullet points'])),
                'closing' => $c['Closing line'] ?: null,
                'min_words' => ctype_digit($c['Minimum words']) ? (int) $c['Minimum words'] : ($number === 1 ? 150 : 250),
                'suggested_minutes' => $number === 1 ? 20 : 40,
            ];
        }
        if (! $tasks) {
            $err('Writing', 1, 'The Writing sheet has no task.');
        }
        ksort($tasks);

        return ['tasks' => array_values($tasks)];
    }

    private function speaking(array $sheets, \Closure $err): array
    {
        $form = [];
        foreach ($sheets['speaking'] ?? [] as ['row' => $n, 'cells' => $c]) {
            $part = (int) $c['Part'];
            $what = $this->key($c['What']);
            $text = trim($c['Text']);
            if (! in_array($part, [1, 2, 3], true)) {
                $err('Speaking', $n, 'Part must be 1, 2 or 3.');

                continue;
            }
            if ($text === '') {
                $err('Speaking', $n, 'Text is empty.');

                continue;
            }
            $key = "part{$part}";
            $form[$key] ??= $part === 2 ? ['topic' => '', 'bullets' => '', 'closing' => null, 'follow_ups' => []] : ['topic' => '', 'questions' => []];
            $allowed = $part === 2 ? ['topic', 'cue card', 'point', 'closing line', 'closing', 'follow up question', 'follow up'] : ['topic', 'question'];
            if (! in_array($what, $allowed, true)) {
                $err('Speaking', $n, $part === 2
                    ? 'In Part 2, What must be Topic, Point, Closing line or Follow-up question.'
                    : "In Part {$part}, What must be Topic or Question.");

                continue;
            }
            match ($what) {
                'topic', 'cue card' => $form[$key]['topic'] = $text,
                'question' => $form[$key]['questions'][] = ['text' => $text],
                'point' => $form[$key]['bullets'] = ltrim($form[$key]['bullets']."\n".$text),
                'closing line', 'closing' => $form[$key]['closing'] = $text,
                default => $form[$key]['follow_ups'][] = ['text' => $text],
            };
        }
        foreach ([1, 2, 3] as $part) {
            if (isset($form["part{$part}"]) && $form["part{$part}"]['topic'] === '') {
                $err('Speaking', 1, "Part {$part} needs a Topic row.");
            }
        }
        if (! $form) {
            $err('Speaking', 1, 'The Speaking sheet is empty.');
        }

        return $form;
    }

    /* ---------- helpers ---------- */

    /** Indexes 0…last, missing ones filled with $blank. */
    private function fillGaps(array $items, array $blank): array
    {
        if (! $items) {
            return [];
        }
        $out = [];
        foreach (range(0, max(array_keys($items))) as $i) {
            $out[] = $items[$i] ?? $blank;
        }

        return $out;
    }

    private function lines(string $cell): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\||\R/', $cell)), fn ($l) => $l !== ''));
    }

    private function yes(string $value): bool
    {
        return in_array($this->key($value), ['yes', 'y', 'true', '1', 'oui', 'x'], true);
    }

    private function key(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }

    private function sheetOrder(string $sheet): int
    {
        return array_search($sheet, ['Details', 'Passages', 'Questions', 'Writing', 'Speaking'], true) ?: 0;
    }

    /* ---------- made-up examples ---------- */

    private const READING_PASSAGES = [
        [1, 'Evening classes at Hillside Centre', 'Autumn term', 'A', 'Spanish for Travellers. Mondays, 7–9 pm. For complete beginners who want to order food and ask for directions. £90 for eight weeks.', ''],
        [1, '', '', 'B', 'Digital Photography. Saturday mornings. Bring your own camera; the class is limited to ten people so everyone gets help. £120.', ''],
        [1, '', '', 'C', 'Home Repairs. Learn to fix a dripping tap and hang a shelf. All tools are provided. £75.', ''],
        [1, 'Library opening hours', '', '', '', 'library-notice.jpg'],
        [2, 'Your first week at Brightway Logistics', '', '', 'On your first morning, go to the main reception at 8.30, where you will receive a temporary ID card. Your permanent card, with your photo, will be ready by Wednesday.', ''],
        [2, '', '', '', 'Everyone must complete a safety course before entering the warehouse. The course takes about an hour and ends with a short quiz.', ''],
        [3, 'The return of the city garden', 'Why more people are growing food at home', 'A', 'Fifty years ago, most city flats had no space for growing vegetables, and gardening was seen as a hobby for retired people.', ''],
        [3, '', '', 'B', 'Today, community gardens are opening in car parks and on rooftops. Many are run by volunteers who share the harvest.', ''],
        [3, '', '', 'C', 'Supporters say the gardens bring neighbours together, although critics point out that they produce only a small amount of food.', ''],
    ];

    private const READING_QUESTIONS = [
        [1, 'Which paragraph contains… (Matching information)', '', 'Which class is described in each statement? Write the correct letter, A, B or C.', '', 'You do not need to bring any equipment.', 'Spanish for Travellers | Digital Photography | Home Repairs', 'C', ''],
        [1, 'Which paragraph contains… (Matching information)', '', '', '', 'The number of students is limited.', '', 'B', ''],
        [1, 'True / False / Not given', '', '', '', 'The Spanish class is suitable for beginners.', '', 'TRUE', ''],
        [1, 'True / False / Not given', '', '', '', 'The library is closed on Sundays.', '', 'NOT GIVEN', ''],
        [1, 'True / False / Not given', '', '', '', 'The photography class costs less than the repairs class.', '', 'FALSE', ''],
        [2, 'Fill gaps in notes (Note completion)', 'First week', 'Complete the notes below.', 'Choose ONE WORD ONLY from the text for each answer.', '- collect a temporary ____ card at reception', '', 'ID', ''],
        [2, 'Fill gaps in notes (Note completion)', '', '', '', '- the permanent card shows your ____', '', 'photo', ''],
        [2, 'Fill gaps in notes (Note completion)', '', '', '', '- the safety course ends with a ____', '', 'quiz', ''],
        [2, 'Answer with a few words (Short answer)', '', '', 'Choose NO MORE THAN TWO WORDS from the text for each answer.', 'Where should new staff go on the first morning?', '', 'main reception|reception', ''],
        [2, 'Answer with a few words (Short answer)', '', '', '', 'How long does the safety course take?', '', 'an hour|one hour|1 hour', ''],
        [3, 'Choose a heading for each paragraph (Matching headings)', 'List of Headings', '', '', 'Paragraph A', 'Gardens in new places | Not everyone is convinced | A hobby for the old | The cost of vegetables', 'iii', ''],
        [3, 'Choose a heading for each paragraph (Matching headings)', '', '', '', 'Paragraph B', '', 'i', ''],
        [3, 'Choose a heading for each paragraph (Matching headings)', '', '', '', 'Paragraph C', '', 'ii', ''],
        [3, 'Choose A, B or C (Multiple choice)', '', '', '', 'Who runs many of the community gardens?', 'The city council | Volunteers | Supermarkets', 'B', ''],
        [3, 'Yes / No / Not given', '', '', '', 'The writer thinks city gardens should get more money.', '', 'NOT GIVEN', ''],
        [3, 'Yes / No / Not given', '', '', '', 'Some people doubt how much food the gardens produce.', '', 'YES', ''],
        [3, 'Fill gaps in a summary (Summary completion)', 'City gardens', 'Complete the summary using the list of words below.', '', 'Community gardens bring ____ together and the harvest is ____.', 'neighbours | shared | sold | tourists', 'A; B', ''],
    ];

    private const WRITING_EXAMPLES = [
        [1, "You recently stayed at a hotel and left a bag in your room.\n\nWrite a letter to the hotel manager.", "say when you stayed at the hotel\ndescribe the bag and what was in it\nexplain how the hotel can return it to you", 'Begin your letter as follows: Dear Sir or Madam,', 150],
        [2, "Some people think that children should start learning a foreign language at primary school. Others believe they should wait until secondary school.\n\nDiscuss both views and give your own opinion.", '', 'Give reasons for your answer and include any relevant examples from your own knowledge or experience.', 250],
    ];

    private const SPEAKING_EXAMPLES = [
        [1, 'Topic', 'Your home town'],
        [1, 'Question', 'Where is your home town?'],
        [1, 'Question', 'What do you like most about it?'],
        [1, 'Question', 'Has it changed much since you were a child?'],
        [2, 'Topic', 'Describe a place you like to visit in your free time.'],
        [2, 'Point', 'where it is'],
        [2, 'Point', 'how often you go there'],
        [2, 'Point', 'what you do there'],
        [2, 'Closing line', 'and explain why you like this place.'],
        [2, 'Follow-up question', 'Do your friends like this place too?'],
        [3, 'Topic', 'Free time and public places'],
        [3, 'Question', 'Why do people need public parks in cities?'],
        [3, 'Question', 'Should museums be free for everyone?'],
    ];
}
