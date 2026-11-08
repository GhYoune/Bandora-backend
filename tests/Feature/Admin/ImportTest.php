<?php

namespace Tests\Feature\Admin;

use App\Enums\Skill;
use App\Filament\Resources\Tests\Pages\ImportTest as ImportPage;
use App\Http\Presenters\Api;
use App\Models\Test;
use App\Models\User;
use App\Services\Tests\TestImporter;
use Livewire\Livewire;
use Tests\TestCase;

/** The "Import from text" page and the paste format behind it (original sample content only). */
class ImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        $this->actingAs(User::where('is_admin', true)->firstOrFail());
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/import-{$name}-sample.txt"));
    }

    private function parse(Skill $skill, string $text): array
    {
        return app(TestImporter::class)->parse($skill, $text);
    }

    public function test_listening_sample_parses_every_question_type(): void
    {
        $r = $this->parse(Skill::Listening, $this->fixture('listening'));

        $this->assertSame([], $r['errors']);
        $this->assertSame(15, $r['questionCount']);
        $groups = array_merge(...array_map(fn ($p) => $p['groups'], $r['content']['parts']));
        $this->assertSame(
            [['gap_fill', 1, 4], ['multiple_choice', 5, 6], ['diagram_label', 7, 9], ['table_completion', 10, 11], ['matching', 12, 13], ['multiple_choice', 14, 15]],
            array_map(fn ($g) => [$g['type'], $g['firstNumber'], $g['lastNumber']], $groups),
        );
        $this->assertSame('Booking a bike tour', $r['form']['parts'][0]['title']);
        $this->assertSame(['0775 318 204', '0775318204'], $r['answerKey'][$groups[0]['questions'][1]['id']]);
        $this->assertSame(['a', 'c'], $r['answerKey'][$groups[5]['questions'][0]['id']]); // Choose TWO
        $this->assertSame([14, 15], $groups[5]['questions'][0]['numbers']);
        $this->assertSame([['key' => '1.0', 'description' => 'Map of the park with six places marked A to F', 'line' => 27]], $r['images']);
        // Incomplete, but importable as a draft.
        $this->assertContains('The test has 15 questions; a real test has 40.', array_column($r['warnings'], 'message'));
    }

    public function test_reading_sample_keeps_texts_and_paragraph_letters(): void
    {
        $r = $this->parse(Skill::Reading, $this->fixture('reading'));

        $this->assertSame([], $r['errors']);
        $passage = $r['content']['sections'][0]['passages'][0];
        $this->assertSame(['Greenfield Community Pool', 'Opening times and rules'], [$passage['title'], $passage['subtitle']]);
        $this->assertSame(['A', 'B'], array_column($passage['paragraphs'], 'label'));
        $groups = $r['content']['sections'][0]['groups'];
        $this->assertSame(['true_false_not_given', 'matching', 'short_answer'], array_column($groups, 'type'));
        $this->assertSame('headings', $groups[1]['variant']);
        $this->assertSame(['iii'], $r['answerKey'][$groups[1]['questions'][0]['id']]);
        $this->assertSame(['not given'], $r['answerKey'][$groups[0]['questions'][2]['id']]);
    }

    public function test_mistakes_are_reported_with_their_line(): void
    {
        $text = <<<'TXT'
        # Part 1
        Questions 1-3
        Type: choise
        Questions 4-5
        Type: choice
        4. Where is the meeting?
        A) the library
        B) the café
        5. When?
        *A) Monday
        *B) Tuesday
        Questions 6-7
        Type: tfng
        6. It is free. [[MAYBE]]
        7. It is open late.
        TXT;
        $r = $this->parse(Skill::Listening, $text);
        $messages = array_map(fn ($e) => "{$e['line']}: {$e['message']}", $r['errors']);

        $this->assertStringContainsString('3: Unknown type "choise"', implode("\n", $messages));
        $this->assertContains('6: Mark the correct option with "*", e.g. "*B) …".', $messages);
        $this->assertStringContainsString('9: Only one option is marked here', implode("\n", $messages));
        $this->assertStringContainsString('14: The answer must be TRUE, FALSE, NOT GIVEN', implode("\n", $messages));
        $this->assertContains('15: No answer found. Write it in double brackets, e.g. [[NOT GIVEN]].', $messages);
    }

    public function test_numbering_mismatch_is_a_warning(): void
    {
        $r = $this->parse(Skill::Reading, "# Section 1\n## Text: Notice\nShort text.\n\nQuestions 1-3\nType: short-answer\n1. Who? [[Ann]]\n2. When? [[May]]");

        $this->assertSame([], $r['errors']);
        $this->assertContains('Questions 1–3: 3 expected, 2 found.', array_column($r['warnings'], 'message'));
    }

    public function test_page_imports_a_draft_and_opens_it(): void
    {
        $this->get('/admin/tests/import')->assertOk();

        Livewire::test(ImportPage::class)
            ->fillForm([
                'skill' => 'reading',
                'title' => 'Reading Test 7',
                'source' => 'Pilot sample',
                'text' => $this->fixture('reading'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $test = Test::firstWhere('title', 'Reading Test 7');
        $this->assertFalse($test->is_published);
        $this->assertSame(7, $test->question_count);
        $this->assertSame('Pilot sample', $test->source);
        $this->assertSame(3600, $test->duration_seconds);
        $this->assertNotEmpty($test->publishProblems());
        // The admin-only source never reaches students.
        $this->assertArrayNotHasKey('source', app(Api::class)->testSummary($test, User::firstWhere('email', 'student@demo.dz'), collect()));
    }

    public function test_page_asks_for_pictures_and_shows_the_preview(): void
    {
        Livewire::test(ImportPage::class)
            ->fillForm(['skill' => 'listening', 'title' => 'Listening Test 8', 'text' => $this->fixture('listening')])
            ->assertSee('Line 27: Map of the park with six places marked A to F')
            ->assertSee('Not ready to publish yet')
            ->assertSee('Questions 14–15');
    }

    public function test_page_refuses_text_with_errors(): void
    {
        Livewire::test(ImportPage::class)
            ->fillForm(['skill' => 'listening', 'title' => 'Broken', 'text' => "# Part 1\nQuestions 1-2\nType: nope"])
            ->call('create')
            ->assertNotified('The test has format errors');

        $this->assertNull(Test::firstWhere('title', 'Broken'));
    }
}
