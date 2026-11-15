<?php

namespace App\Services\Tests;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The question spreadsheet the teacher fills in (the "Import questions" button on a test), and
 * the sheets it shares with the full-test workbook (TestWorkbookTemplate):
 * - Questions: the rows to fill in, with a dropdown on the Type column;
 * - Question types: one ready example of every type to copy, unused columns greyed out.
 * All examples are made up.
 */
class QuestionTemplate
{
    /** Listening examples (Part, Type, Title, Instructions, Word limit, Question, Options, Answer, Picture). */
    public const LISTENING_EXAMPLES = [
        [1, 'Fill gaps in a form (Form completion)', 'Sports centre membership', 'Complete the form below.', 'Write ONE WORD AND/OR A NUMBER for each answer.', 'Name: Anna ____', '', 'Kowalski', ''],
        [1, 'Fill gaps in a form (Form completion)', '', '', '', 'Phone: ____', '', '07946 223190', ''],
        [1, 'Fill gaps in a form (Form completion)', '', '', '', 'Preferred class: ____ on Tuesdays', '', 'yoga', ''],
        [1, 'Fill gaps in a form (Form completion)', '', '', '', 'Monthly fee: £____', '', '32', ''],
        [1, 'Fill gaps in a form (Form completion)', '', '', '', 'Will bring her own ____ and ____', '', 'towel; mat|yoga mat', ''],
        [2, 'Choose A, B or C (Multiple choice)', '', '', '', 'Why was the park closed last year?', 'Flooding | Repairs to the paths | A lack of staff', 'B', ''],
        [2, 'Choose TWO letters (Multiple choice)', '', '', '', 'Which TWO activities are free for children?', 'Swimming | Climbing | Archery | Mini golf | Pony rides', 'B, D', ''],
        [2, 'Label a map (Map labelling)', 'Riverside Park', '', '', 'Café', 'A-H', 'C', 'park-map.png'],
        [2, 'Label a map (Map labelling)', '', '', '', 'Boat hire', '', 'F', ''],
        [3, 'Match each item to a list (Matching)', 'Opinions', 'What does each student think about the course?', '', 'Statistics module', 'Too difficult | Very useful | Badly organised | Too short', 'B', ''],
        [3, 'Match each item to a list (Matching)', '', '', '', 'Field trip', '', 'D', ''],
        [3, 'Answer with a few words (Short answer)', '', '', 'Write NO MORE THAN TWO WORDS for each answer.', 'Where will the students present their project?', '', 'main hall|the main hall', ''],
        [4, 'Fill gaps in notes (Note completion)', 'The history of the bicycle', 'Complete the notes below.', 'Write ONE WORD ONLY for each answer.', '# Early designs', '', '', ''],
        [4, 'Fill gaps in notes (Note completion)', '', '', '', '- the first models were made of ____', '', 'wood', ''],
        [4, 'Fill gaps in notes (Note completion)', '', '', '', '- riders pushed with their ____', '', 'feet', ''],
        [4, 'Fill gaps in a table (Table completion)', 'Bicycle types', 'Complete the table below.', '', 'Type | Main use | Problem', '', '', ''],
        [4, 'Fill gaps in a table (Table completion)', '', '', '', 'Road bike | ____ | expensive', '', 'racing', ''],
        [4, 'Fill gaps in a table (Table completion)', '', '', '', 'Folding bike | commuting | small ____', '', 'wheels', ''],
        [4, 'True / False / Not given', '', '', '', 'Cycling became popular in cities before the countryside.', '', 'NOT GIVEN', ''],
        [4, 'Yes / No / Not given', '', '', '', 'The writer believes cycle lanes should be wider.', '', 'YES', ''],
        [4, 'Label a diagram (Diagram labelling)', 'How a bicycle brake works', '', '', 'The rubber ____ presses on the wheel', '', 'pad', 'brake-diagram.png'],
    ];

    /** One example per type for the "Question types" sheet: [type label, rows of (Title … Picture)]. */
    private const TYPE_EXAMPLES = [
        'Fill gaps in notes (Note completion)' => [
            ['Opening a café', 'Complete the notes below.', 'Write ONE WORD ONLY for each answer.', '# Location', '', '', ''],
            ['', '', '', '- near the ____ station', '', 'bus', ''],
            ['', '', '', '- rent is paid every ____', '', 'month', ''],
        ],
        'Fill gaps in a form (Form completion)' => [
            ['Hotel booking', 'Complete the form below.', 'Write ONE WORD AND/OR A NUMBER for each answer.', 'Surname: ____', '', 'Patel', ''],
            ['', '', '', 'Number of nights: ____', '', '3|three', ''],
        ],
        'Fill gaps in sentences (Sentence completion)' => [
            ['', 'Complete the sentences below.', 'Write NO MORE THAN TWO WORDS for each answer.', 'The museum opens at ____ on Sundays.', '', '10 am|10am|10', ''],
        ],
        'Fill gaps in a summary (Summary completion)' => [
            ['The new library', 'Complete the summary using the list of words below.', '', 'The new library is ____ and very ____ in the evenings.', 'cheap | crowded | modern | quiet', 'modern; quiet', ''],
        ],
        'Fill gaps in a flow chart (Flow-chart completion)' => [
            ['Applying for a parking permit', 'Complete the flow chart below.', 'Write ONE WORD ONLY for each answer.', '- Fill in the application ____', '', 'form', ''],
            ['', '', '', '- Wait for an ____ from the council', '', 'email', ''],
        ],
        'Fill gaps in a table (Table completion)' => [
            ['Evening classes', 'Complete the table below.', 'Write ONE WORD AND/OR A NUMBER for each answer.', 'Course | Day | Price', '', '', ''],
            ['', '', '', 'Pottery | ____ | £40', '', 'Monday', ''],
            ['', '', '', 'Photography | Thursday | £____', '', '55', ''],
        ],
        'Choose A, B or C (Multiple choice)' => [
            ['', 'Choose the correct letter, A, B or C.', '', 'What will the group do first?', 'Go for a walk | Attend a lecture | Watch a film', 'B', ''],
        ],
        'Choose TWO letters (Multiple choice)' => [
            ['', 'Choose TWO letters, A–E.', '', 'Which TWO things must visitors bring?', 'A towel | Sun cream | Water | A map | A hat', 'B, C', ''],
        ],
        'True / False / Not given' => [
            ['', '', '', 'The festival takes place every summer.', '', 'TRUE', ''],
        ],
        'Yes / No / Not given' => [
            ['', '', '', 'The writer thinks working from home saves money.', '', 'NO', ''],
        ],
        'Match each item to a list (Matching)' => [
            ['Hotels', 'Which hotel offers each service?', '', 'Free breakfast', 'Hotel Bay | Grand Hotel | Park Inn', 'A', ''],
            ['', '', '', 'Airport bus', '', 'C', ''],
        ],
        'Choose a heading for each paragraph (Matching headings)' => [
            ['List of Headings', '', '', 'Paragraph A', 'A growing problem | Early solutions | Looking ahead', 'ii', ''],
            ['', '', '', 'Paragraph B', '', 'i', ''],
        ],
        'Which paragraph contains… (Matching information)' => [
            ['', 'Which paragraph contains the following information?', '', 'a reason for a change in price', 'Paragraph A | Paragraph B | Paragraph C | Paragraph D', 'C', ''],
        ],
        'Match people or things to statements (Matching features)' => [
            ['List of Researchers', 'Match each statement with the correct researcher.', '', 'Sleep affects memory.', 'Dr Lane | Prof Okafor | Dr Silva', 'B', ''],
        ],
        'Finish each sentence from a list (Sentence endings)' => [
            ['', 'Complete each sentence with the correct ending.', '', 'Most new staff', 'enjoy the training. | leave within a year. | ask for more pay.', 'A', ''],
        ],
        'Label a map (Map labelling)' => [
            ['Town centre', '', '', 'Post office', 'A-G', 'D', 'town-map.png'],
            ['', '', '', 'Car park', '', 'A', ''],
        ],
        'Label a diagram (Diagram labelling)' => [
            ['A water filter', '', 'Write ONE WORD ONLY for each answer.', 'Water passes through a layer of ____', '', 'sand', 'filter.png'],
        ],
        'Answer with a few words (Short answer)' => [
            ['', 'Answer the questions below.', 'Write NO MORE THAN THREE WORDS for each answer.', 'What does the speaker sell?', '', 'second-hand books', ''],
        ],
    ];

    public const QUESTIONS_SHEET = 'Questions';

    public const TYPES_SHEET = 'Question types';

    public const GAP_HELP = 'Mark each gap with ____ (four underscores) and put its answer in Answer. Several gaps on one row: separate the answers with ";". Other accepted answers with |, e.g. colour|color.';

    public function write(string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        self::questionsSheet($writer->getCurrentSheet(), $writer, self::LISTENING_EXAMPLES, 'Listening: 1 to 4. Reading: the section, 1 to 3.');
        self::typesSheet($writer->addNewSheetAndMakeItCurrent(), $writer);
        self::helpSheet($writer->addNewSheetAndMakeItCurrent(), $writer, [
            'Replace the example rows on the Questions sheet with your own. Keep the first row (the column names).',
            'Part: Listening 1 to 4, Reading the section 1 to 3.',
        ]);
        $writer->close();
        self::addDropdowns($path, [1 => [self::typeDropdown('B2:B1000')]]);
    }

    /* ---------- shared with the full-test workbook ---------- */

    public static function bold(): Style
    {
        return (new Style)->setFontBold()->setBackgroundColor('E7EFEC');
    }

    public static function grey(): Style
    {
        return (new Style)->setBackgroundColor('E5E7EB');
    }

    public static function wrap(): Style
    {
        return (new Style)->setShouldWrapText();
    }

    /** The Questions sheet: headings, then $examples, Type cells with a dropdown (added by addDropdowns). */
    public static function questionsSheet(Sheet $sheet, Writer $writer, array $examples, string $partHelp): void
    {
        $sheet->setName(self::QUESTIONS_SHEET);
        self::columnWidths($sheet);
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        $writer->addRow(Row::fromValues(SpreadsheetImporter::COLUMNS, self::bold()));
        foreach ($examples as $example) {
            $writer->addRow(self::exampleRow($example));
        }
    }

    /** Question types: one example per type with the columns it does not use greyed out, and the dropdown list. */
    public static function typesSheet(Sheet $sheet, Writer $writer): void
    {
        $sheet->setName(self::TYPES_SHEET);
        self::columnWidths($sheet);
        $sheet->setColumnWidth(60, 10);
        $sheet->setColumnWidth(4, 11);
        $sheet->setColumnWidth(60, 12);
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        $writer->addRow(Row::fromValues([...SpreadsheetImporter::COLUMNS, 'What the student sees', '', 'Type names (used by the dropdown)'], self::bold()));
        $labels = array_keys(SpreadsheetImporter::TYPE_LABELS);
        $listRow = 0;
        $rows = [];
        foreach (self::TYPE_EXAMPLES as $label => $exampleRows) {
            foreach ($exampleRows as $i => $cells) {
                $rows[] = [[1, $label, ...$cells], $i === 0 ? SpreadsheetImporter::TYPE_LABELS[$label][2] : ''];
            }
            $rows[] = null; // blank line between types
        }
        foreach ($rows as $row) {
            $listCell = $labels[$listRow] ?? '';
            $listRow++;
            if ($row === null) {
                $writer->addRow(Row::fromValues(array_merge(array_fill(0, 11, ''), [$listCell])));

                continue;
            }
            [$values, $hint] = $row;
            $cells = self::exampleRow($values)->getCells();
            $cells[] = Cell::fromValue($hint, self::wrap());
            $cells[] = Cell::fromValue('');
            $cells[] = Cell::fromValue($listCell);
            $writer->addRow(new Row($cells));
        }
    }

    /** A "How to fill it in" sheet: $intro lines, then the question columns and types explained. */
    public static function helpSheet(Sheet $sheet, Writer $writer, array $intro, array $extraSections = []): void
    {
        $sheet->setName('How to fill it in');
        $sheet->setColumnWidth(28, 1);
        $sheet->setColumnWidth(110, 2);
        foreach ($intro as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }
        foreach ($extraSections as $title => $rows) {
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues([$title, ''], self::bold()));
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row, self::wrap()));
            }
        }
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['Questions sheet', ''], self::bold()));
        foreach ([
            ['Part', 'Listening: the part, 1 to 4. Reading: the section, 1 to 3.'],
            ['Type', 'Pick it from the dropdown: it says what the student sees. Not sure? Open the "Question types" sheet, find the example that looks like your page and copy its rows.'],
            ['Title', 'Optional. Title of the form, notes, table, map or list of options. A filled Title starts a new set of questions.'],
            ['Instructions', 'Optional. Leave empty for the standard IELTS wording. A filled cell starts a new set of questions.'],
            ['Word limit', 'Optional, e.g. Write ONE WORD AND/OR A NUMBER for each answer.'],
            ['Question', 'The question, statement or line of notes. '.self::GAP_HELP],
            ['Options', 'The choices separated by | (multiple choice, matching, word box), or the letters on a map (A-H). Only on the first row of a set.'],
            ['Answer', 'The answer: a letter, TRUE / FALSE / NOT GIVEN, or the words. Other accepted answers with |.'],
            ['Picture', 'Optional. The file name of a picture shown above the questions (or the map / diagram itself). Upload the picture together with the spreadsheet.'],
            ['Grey cells', 'On the Question types sheet, grey cells are not used by that type: leave them empty.'],
            ['Numbers', 'Question numbers are added automatically, in order.'],
        ] as $row) {
            $writer->addRow(Row::fromValues($row, self::wrap()));
        }
    }

    /** An example question row: unused columns of its type greyed out. */
    private static function exampleRow(array $values): Row
    {
        $type = SpreadsheetImporter::typeOf((string) ($values[1] ?? ''));
        $used = $type ? SpreadsheetImporter::USED_COLUMNS[$type[0]] : SpreadsheetImporter::COLUMNS;
        $cells = [];
        foreach (SpreadsheetImporter::COLUMNS as $i => $col) {
            $value = $values[$i] ?? '';
            $style = in_array($col, ['Part', 'Type'], true) || in_array($col, $used, true) ? null : self::grey();
            $cells[] = Cell::fromValue($value === '' ? null : $value, $style);
        }

        return new Row($cells);
    }

    private static function columnWidths(Sheet $sheet): void
    {
        $sheet->setColumnWidth(7, 1);
        $sheet->setColumnWidth(46, 2);
        $sheet->setColumnWidth(22, 3);
        $sheet->setColumnWidth(26, 4, 5);
        $sheet->setColumnWidth(50, 6);
        $sheet->setColumnWidth(36, 7);
        $sheet->setColumnWidth(22, 8, 9);
    }

    /** Dropdown of the type names, kept on the Question types sheet. */
    public static function typeDropdown(string $range): array
    {
        $count = count(SpreadsheetImporter::TYPE_LABELS);

        return [$range, "'".self::TYPES_SHEET."'!\$L\$2:\$L\$".($count + 1)];
    }

    /**
     * openspout cannot write data validation, so the dropdowns are added to the sheet XML after
     * the file is written. $dropdowns: sheet number (1-based) → list of [range, list formula].
     * A formula is either a range ('Sheet'!$A$1:$A$9) or the choices quoted: "yes,no".
     *
     * @param  array<int, list<array{0: string, 1: string}>>  $dropdowns
     */
    public static function addDropdowns(string $path, array $dropdowns): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return;
        }
        foreach ($dropdowns as $sheet => $lists) {
            $name = "xl/worksheets/sheet{$sheet}.xml";
            $xml = $zip->getFromName($name);
            if ($xml === false || ! $lists) {
                continue;
            }
            $items = implode('', array_map(fn ($l) => '<dataValidation type="list" allowBlank="1" showErrorMessage="0" sqref="'.$l[0].'"><formula1>'.htmlspecialchars($l[1], ENT_XML1).'</formula1></dataValidation>', $lists));
            $block = '<dataValidations count="'.count($lists).'">'.$items.'</dataValidations>';
            // Schema order: dataValidations come after sheetData/mergeCells and before these.
            if (preg_match('/<(hyperlinks|printOptions|pageMargins|pageSetup|headerFooter|drawing|legacyDrawing|tableParts)\b/', $xml, $m, PREG_OFFSET_CAPTURE)) {
                $xml = substr_replace($xml, $block, $m[0][1], 0);
            } else {
                $xml = str_replace('</worksheet>', $block.'</worksheet>', $xml);
            }
            $zip->addFromString($name, $xml);
        }
        $zip->close();
    }
}
