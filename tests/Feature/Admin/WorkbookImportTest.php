<?php

namespace Tests\Feature\Admin;

use App\Enums\Skill;
use App\Filament\Resources\Tests\Pages\ListTests;
use App\Filament\Resources\Tests\TestResource;
use App\Models\Test;
use App\Models\User;
use App\Services\Audio\RecordingSplitter;
use App\Services\Tests\SpreadsheetImporter;
use App\Services\Tests\TestWorkbook;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class WorkbookImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('public');
    }

    private function template(Skill $skill): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        app(TestWorkbook::class)->writeTemplate($path, $skill);

        return $path;
    }

    /** @param  array<string, list<list<mixed>>>  $sheets  sheet name → rows, the first row being the headings */
    private function workbook(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;
        foreach ($sheets as $name => $rows) {
            ($first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent())->setName($name);
            $first = false;
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }
        $writer->close();

        return UploadedFile::fake()->createWithContent('my-test.xlsx', file_get_contents($path));
    }

    private function details(string $skill, array $extra = []): array
    {
        return [TestWorkbook::DETAILS, ['Title', 'Imported test'], ['Skill', $skill], ['Published', 'no'], ...$extra];
    }

    public function test_every_template_imports_without_mistakes(): void
    {
        $workbook = app(TestWorkbook::class);
        $media = ['test1-full.mp3' => 'tests/audio/full.mp3', 'park-map.png' => 'a.png', 'brake-diagram.png' => 'b.png', 'library-notice.jpg' => 'c.jpg'];
        $expected = ['listening' => 21, 'reading' => 18, 'writing' => 2, 'speaking' => 7];

        foreach (Skill::cases() as $skill) {
            $result = $workbook->parse($workbook->read($this->template($skill)), $media);

            $this->assertSame([], $result['errors'], $skill->value);
            $this->assertSame($expected[$skill->value], $result['questionCount'], $skill->value);
            $this->assertSame($skill->value, $result['data']['skill']);
        }
    }

    public function test_the_type_column_accepts_plain_names_old_names_and_the_ielts_name_in_brackets(): void
    {
        $this->assertSame(['gap_fill', 'notes'], SpreadsheetImporter::typeOf('Fill gaps in notes (Note completion)'));
        $this->assertSame(['gap_fill', 'notes'], SpreadsheetImporter::typeOf('notes'));
        $this->assertSame(['matching', 'headings'], SpreadsheetImporter::typeOf('anything (Matching headings)'));
        $this->assertSame(['true_false_not_given', 'ynng'], SpreadsheetImporter::typeOf('yes/no/not given'));
        $this->assertNull(SpreadsheetImporter::typeOf('quiz'));
    }

    public function test_the_reading_template_creates_a_draft_test_with_its_scanned_paragraph(): void
    {
        $sheet = UploadedFile::fake()->createWithContent('reading.xlsx', file_get_contents($this->template(Skill::Reading)));

        Livewire::test(ListTests::class)
            ->callAction('importWorkbook', ['workbook' => $sheet, 'pictures' => [UploadedFile::fake()->image('Library-Notice.jpg')]])
            ->assertHasNoActionErrors()
            ->assertNotified('Test created');

        $test = Test::firstWhere('title', 'Practice Test 1');
        $this->assertSame(Skill::Reading, $test->skill);
        $this->assertFalse($test->is_published);
        $this->assertSame(18, $test->question_count);
        $this->assertSame(3600, $test->duration_seconds);
        $this->assertCount(3, $test->content['sections']);
        $notice = $test->content['sections'][0]['passages'][1];
        $this->assertSame('Library opening hours', $notice['title']);
        $this->assertStringContainsString('tests/images/', $notice['paragraphs'][0]['picture']['url']);
        $this->assertSame(['c'], $test->answer_key[$test->content['sections'][0]['groups'][0]['questions'][0]['id']]);
    }

    public function test_writing_and_speaking_workbooks_create_their_tasks_and_parts(): void
    {
        foreach ([Skill::Writing, Skill::Speaking] as $skill) {
            $sheet = UploadedFile::fake()->createWithContent("{$skill->value}.xlsx", file_get_contents($this->template($skill)));
            Livewire::test(ListTests::class)
                ->callAction('importWorkbook', ['workbook' => $sheet])
                ->assertHasNoActionErrors();
        }

        $writing = Test::firstWhere('skill', Skill::Writing);
        $this->assertSame([1, 2], array_column($writing->content['tasks'], 'number'));
        $this->assertCount(3, $writing->content['tasks'][0]['bullets']);
        $this->assertSame('letter', $writing->content['tasks'][0]['kind']);

        $speaking = Test::firstWhere('skill', Skill::Speaking);
        $parts = $speaking->content['parts'];
        $this->assertSame(['interview', 'cue_card', 'discussion'], array_column($parts, 'kind'));
        $this->assertSame(['where it is', 'how often you go there', 'what you do there'], $parts[1]['bullets']);
        $this->assertCount(1, $parts[1]['followUps']);
        $this->assertSame(840, $speaking->duration_seconds);
    }

    public function test_mistakes_are_listed_with_their_sheet_and_row_and_nothing_is_created(): void
    {
        $sheet = $this->workbook([
            'Details' => $this->details('reading'),
            'Passages' => [TestWorkbook::PASSAGES, [1, '', '', '', 'A paragraph without a title.', ''], [4, 'Wrong section', '', '', 'x', '']],
            'Questions' => [SpreadsheetImporter::COLUMNS, [1, 'quiz', '', '', '', 'Why?', '', 'x', ''], [2, 'True / False / Not given', '', '', '', 'The sky is green.', '', 'maybe', 'sky.png']],
        ]);

        $workbook = app(TestWorkbook::class);
        $file = $sheet->getRealPath();
        $errors = collect($workbook->parse($workbook->read($file, 'xlsx'))['errors'])
            ->map(fn ($e) => "{$e['sheet']} {$e['row']}: {$e['message']}");

        $this->assertTrue($errors->contains(fn ($e) => str_starts_with($e, 'Passages 2: Write the title')));
        $this->assertTrue($errors->contains(fn ($e) => str_starts_with($e, 'Passages 3: Section must be')));
        $this->assertTrue($errors->contains(fn ($e) => str_starts_with($e, 'Questions 2: Unknown type "quiz"')));
        $this->assertTrue($errors->contains(fn ($e) => str_starts_with($e, 'Questions 3: Answer must be TRUE')));
        $this->assertTrue($errors->contains(fn ($e) => str_starts_with($e, 'Questions 3: Picture "sky.png" was not uploaded')));

        Livewire::test(ListTests::class)
            ->callAction('importWorkbook', ['workbook' => $sheet])
            ->assertNotified('The workbook has mistakes');
        $this->assertSame(0, Test::count());
    }

    public function test_a_file_without_a_details_sheet_is_refused(): void
    {
        $sheet = $this->workbook(['Sheet1' => [['Hello']]]);
        $workbook = app(TestWorkbook::class);

        $errors = $workbook->parse($workbook->read($sheet->getRealPath(), 'xlsx'))['errors'];

        $this->assertStringContainsString('Details sheet is missing', $errors[0]['message']);
    }

    public function test_published_but_incomplete_is_created_as_a_draft(): void
    {
        $sheet = $this->workbook([
            'Details' => [...$this->details('writing'), ['Published', 'yes']],
            'Writing' => [TestWorkbook::WRITING, [2, 'Some people say homework is useless. Discuss.', '', '', '']],
        ]);
        $sheet2 = $this->workbook([
            'Details' => [TestWorkbook::DETAILS, ['Title', 'Short listening'], ['Skill', 'listening'], ['Published', 'yes']],
            'Questions' => [SpreadsheetImporter::COLUMNS, [1, 'Answer with a few words (Short answer)', '', '', '', 'Which day?', '', 'Monday', '']],
        ]);

        // A Task 2 on its own is a complete Writing test: published.
        Livewire::test(ListTests::class)->callAction('importWorkbook', ['workbook' => $sheet])->assertNotified('Test created');
        $this->assertTrue(Test::firstWhere('title', 'Imported test')->is_published);

        // One question and no recording: kept as a draft, with the reasons.
        Livewire::test(ListTests::class)->callAction('importWorkbook', ['workbook' => $sheet2])
            ->assertNotified('Test created as a draft, not published yet');
        $this->assertFalse(Test::firstWhere('title', 'Short listening')->is_published);
    }

    public function test_the_full_recording_is_cut_into_the_four_parts(): void
    {
        if (! RecordingSplitter::available()) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }
        $mp3 = tempnam(sys_get_temp_dir(), 'rec').'.mp3';
        Process::run(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=8', '-q:a', '9', $mp3])->throw();
        $sheet = $this->workbook([
            'Details' => $this->details('listening', [['Full recording', 'Full.mp3'], ['Part 2 starts', '0:02'], ['Part 3 starts', '0:04'], ['Part 4 starts', '0:06']]),
            'Questions' => [SpreadsheetImporter::COLUMNS,
                [1, 'Answer with a few words (Short answer)', '', '', '', 'Which day?', '', 'Monday', ''],
                [4, 'Answer with a few words (Short answer)', '', '', '', 'Which month?', '', 'May', ''],
            ],
        ]);

        Livewire::test(ListTests::class)
            ->callAction('importWorkbook', ['workbook' => $sheet, 'recordings' => [UploadedFile::fake()->createWithContent('full.mp3', file_get_contents($mp3))]])
            ->assertHasNoActionErrors();

        $test = Test::firstWhere('title', 'Imported test');
        $this->assertCount(4, $test->content['parts']);
        foreach ($test->form['parts'] as $i => $part) {
            Storage::disk('public')->assertExists($part['audio']);
            $this->assertStringEndsWith('-part'.($i + 1).'.mp3', $part['audio']);
            $this->assertEqualsWithDelta(2, $part['audio_duration'], 0.3);
        }
        $this->assertSame([1, 2], [$test->content['parts'][0]['groups'][0]['firstNumber'], $test->content['parts'][3]['groups'][0]['firstNumber']]);
    }

    public function test_start_times_in_the_wrong_order_are_reported(): void
    {
        $workbook = app(TestWorkbook::class);
        $sheet = $this->workbook([
            'Details' => $this->details('listening', [['Full recording', 'full.mp3'], ['Part 2 starts', '10:00'], ['Part 3 starts', '5:00'], ['Part 4 starts', 'soon']]),
        ]);

        $errors = collect($workbook->parse($workbook->read($sheet->getRealPath(), 'xlsx'), ['full.mp3' => 'tests/audio/full.mp3'])['errors']);

        $this->assertSame(['Details'], $errors->pluck('sheet')->unique()->values()->all());
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('Part 3 starts must be minutes:seconds', $errors[0]['message']);
    }

    public function test_the_template_downloads(): void
    {
        Livewire::test(ListTests::class)
            ->callAction('workbookTemplate', ['skill' => 'reading'])
            ->assertFileDownloaded('bandora-reading-test-template.xlsx');
    }

    public function test_after_creating_the_teacher_lands_on_the_edit_page(): void
    {
        $sheet = $this->workbook([
            'Details' => $this->details('speaking'),
            'Speaking' => [TestWorkbook::SPEAKING, [1, 'Topic', 'Food'], [1, 'Question', 'What do you like to eat?']],
        ]);

        $component = Livewire::test(ListTests::class)->callAction('importWorkbook', ['workbook' => $sheet]);

        $component->assertRedirect(TestResource::getUrl('edit', ['record' => Test::first()]));
    }
}
