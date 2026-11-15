<?php

namespace Tests\Feature\Admin;

use App\Enums\Skill;
use App\Filament\Resources\Tests\Pages\CreateTest;
use App\Filament\Resources\Tests\Pages\EditTest;
use App\Models\Test;
use App\Models\User;
use App\Services\Tests\QuestionTemplate;
use App\Services\Tests\SpreadsheetImporter;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class QuestionImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('public');
    }

    private function csv(array $rows): UploadedFile
    {
        $lines = [implode(',', SpreadsheetImporter::COLUMNS)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', array_pad($row, 9, '')));
        }

        return UploadedFile::fake()->createWithContent('questions.csv', implode("\n", $lines));
    }

    public function test_the_template_imports_without_mistakes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        app(QuestionTemplate::class)->write($path);
        $importer = app(SpreadsheetImporter::class);

        $result = $importer->parse(Skill::Listening, $importer->read($path), ['park-map.png' => 'a.png', 'brake-diagram.png' => 'b.png']);

        $this->assertSame([], $result['errors']);
        $this->assertSame(21, $result['count']);
        $this->assertCount(4, $result['containers']);
    }

    public function test_mistakes_are_reported_with_their_row(): void
    {
        $importer = app(SpreadsheetImporter::class);
        $rows = [
            ['row' => 2, 'cells' => array_combine(SpreadsheetImporter::COLUMNS, ['1', 'notes', '', '', '', 'Name: ____ and ____', '', 'Sarah', ''])],
            ['row' => 3, 'cells' => array_combine(SpreadsheetImporter::COLUMNS, ['5', 'notes', '', '', '', 'x', '', '', ''])],
            ['row' => 4, 'cells' => array_combine(SpreadsheetImporter::COLUMNS, ['2', 'quiz', '', '', '', 'x', '', '', ''])],
            ['row' => 5, 'cells' => array_combine(SpreadsheetImporter::COLUMNS, ['2', 'tfng', '', '', '', 'The sky is green.', '', 'maybe', ''])],
            ['row' => 6, 'cells' => array_combine(SpreadsheetImporter::COLUMNS, ['3', 'map', '', '', '', 'Café', 'A-F', 'C', 'missing.png'])],
        ];

        $errors = collect($importer->parse(Skill::Listening, $rows)['errors'])->mapWithKeys(fn ($e) => [$e['row'] => $e['message']]);

        $this->assertStringContainsString('2 gap(s) but Answer has 1', $errors[2]);
        $this->assertStringContainsString('from 1 to 4', $errors[3]);
        $this->assertStringContainsString('Unknown type "quiz"', $errors[4]);
        $this->assertStringContainsString('TRUE, FALSE, NOT GIVEN', $errors[5]);
        $this->assertStringContainsString('"missing.png" was not uploaded', $errors[6]);
    }

    public function test_teacher_imports_questions_and_pictures_into_the_tabs(): void
    {
        $sheet = $this->csv([
            [1, 'form', 'Booking', '', '', 'Name: ____', '', 'Mitchell'],
            [1, 'form', '', '', '', 'Nights: ____', '', '3|three'],
            [2, 'multiple choice', '', '', '', 'Why did she call?', 'To book | To cancel | To complain', 'B', 'phone.png'],
        ]);
        $picture = UploadedFile::fake()->image('phone.png');

        Livewire::test(CreateTest::class)
            ->fillForm(['skill' => Skill::Listening->value, 'title' => 'Imported', 'duration_seconds' => 2400])
            ->callAction('importQuestions', ['sheet' => $sheet, 'pictures' => [$picture]])
            ->assertHasNoActionErrors()
            ->assertNotified('3 questions added')
            ->call('create')
            ->assertHasNoFormErrors();

        $test = Test::firstWhere('title', 'Imported');
        $this->assertSame(3, $test->question_count);
        $this->assertFalse($test->is_published);
        $group = $test->content['parts'][1]['groups'][0];
        $this->assertSame([3, 3], [$group['firstNumber'], $group['lastNumber']]);
        $this->assertStringContainsString('tests/images/', $group['picture']['url']);
        $this->assertContains(['3', 'three'], $test->answer_key);
    }

    public function test_an_import_with_mistakes_adds_nothing(): void
    {
        $test = $this->draftListening();

        Livewire::test(EditTest::class, ['record' => $test->id])
            ->callAction('importQuestions', ['sheet' => $this->csv([[1, 'short answer', '', '', '', 'Where?', '', '']])])
            ->assertNotified('The spreadsheet has mistakes');

        $this->assertSame(1, $test->fresh()->question_count);
    }

    public function test_replace_swaps_the_questions_of_the_imported_parts_only(): void
    {
        $test = $this->draftListening();

        Livewire::test(EditTest::class, ['record' => $test->id])
            ->callAction('importQuestions', ['replace' => true, 'sheet' => $this->csv([
                [1, 'short answer', '', '', '', 'Which day?', '', 'Monday'],
                [1, 'short answer', '', '', '', 'Which month?', '', 'May'],
            ])])
            ->call('save')
            ->assertHasNoFormErrors();

        $prompts = collect($test->fresh()->content['parts'][0]['groups'])->flatMap(fn ($g) => array_column($g['questions'], 'prompt'));
        $this->assertSame(['Which day?', 'Which month?'], $prompts->all());
    }

    public function test_a_draft_saves_half_filled_questions(): void
    {
        Livewire::test(CreateTest::class)
            ->fillForm([
                'skill' => Skill::Reading->value, 'title' => 'Half done', 'duration_seconds' => 3600,
                'form.sections.0.groups' => [
                    ['type' => 'short_answer', 'data' => ['questions' => [['prompt' => 'Where is it?', 'answer' => '']]]],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Test::firstWhere('title', 'Half done')->question_count);
    }

    public function test_publishing_asks_for_the_missing_fields(): void
    {
        Livewire::test(CreateTest::class)
            ->fillForm([
                'skill' => Skill::Reading->value, 'title' => 'Rushed', 'duration_seconds' => 3600, 'is_published' => true,
                'form.sections.0.groups' => [
                    ['type' => 'short_answer', 'data' => ['questions' => [['prompt' => 'Where is it?', 'answer' => '']]]],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertNull(Test::firstWhere('title', 'Rushed'));
    }

    public function test_the_template_downloads(): void
    {
        Livewire::test(CreateTest::class)
            ->fillForm(['skill' => Skill::Listening->value])
            ->callAction('questionTemplate')
            ->assertFileDownloaded('bandora-questions-template.xlsx');
    }

    private function draftListening(): Test
    {
        return Test::create([
            'skill' => Skill::Listening, 'title' => 'Draft', 'duration_seconds' => 2400,
            'form' => ['parts' => [['groups' => [['type' => 'short_answer', 'data' => ['questions' => [['prompt' => 'Old?', 'answer' => 'yes']]]]]]]],
        ]);
    }
}
