<?php

namespace Tests\Feature;

use App\Enums\Skill;
use App\Models\Test;
use App\Services\Tests\TestCompiler;
use Tests\TestCase;

class TestCompilerTest extends TestCase
{
    private function compile(Skill $skill, array $form)
    {
        return app(TestCompiler::class)->compile($skill, $form);
    }

    public function test_sample_tests_have_forty_questions_numbered_in_order(): void
    {
        foreach (['listening-1' => Skill::Listening, 'reading-1' => Skill::Reading] as $file => $skill) {
            $compiled = $this->compile($skill, require database_path("seeders/content/{$file}.php"));
            $this->assertSame(40, $compiled->questionCount, $file);

            $sections = $compiled->content['parts'] ?? $compiled->content['sections'];
            $numbers = collect($sections)->flatMap(fn ($s) => $s['groups'])->flatMap(fn ($g) => collect($g['questions'])
                ->flatMap(fn ($q) => $q['numbers'] ?? [$q['number']]))->all();
            $this->assertSame(range(1, 40), $numbers, $file);

            $ids = collect($sections)->flatMap(fn ($s) => $s['groups'])->flatMap(fn ($g) => array_column($g['questions'], 'id'));
            $this->assertEqualsCanonicalizing($ids->all(), array_keys($compiled->answerKey), "{$file}: one key entry per question id");
        }
    }

    public function test_gaps_are_compiled_and_answers_kept_out_of_the_content(): void
    {
        $compiled = $this->compile(Skill::Listening, ['parts' => [['groups' => [
            ['type' => 'gap_fill', 'data' => ['text' => "Name: Sarah [[Mitchell]]\nColour: [[colour|color]]"]],
        ]]]]);

        $group = $compiled->content['parts'][0]['groups'][0];
        $this->assertSame([1, 2], [$group['firstNumber'], $group['lastNumber']]);
        $this->assertStringNotContainsString('Mitchell', $group['content']);
        $this->assertMatchesRegularExpression('/^Name: Sarah \[\[[\w-]+\]\]/', $group['content']);
        $this->assertSame([['mitchell'], ['colour', 'color']], array_values($compiled->answerKey));
        $this->assertSame('Complete the notes below.', $group['instructions']);
    }

    public function test_ids_are_written_back_and_stable_across_edits(): void
    {
        $first = $this->compile(Skill::Reading, ['sections' => [['groups' => [
            ['type' => 'short_answer', 'data' => ['questions' => [['prompt' => 'Q?', 'answer' => 'yes']]]],
        ]]]]);
        $id = $first->content['sections'][0]['groups'][0]['questions'][0]['id'];

        // The teacher edits the prompt; the saved form already has the id.
        $form = $first->form;
        $form['sections'][0]['groups'][0]['data']['questions'][0]['prompt'] = 'Edited?';
        $second = $this->compile(Skill::Reading, $form);

        $this->assertSame($id, $second->content['sections'][0]['groups'][0]['questions'][0]['id']);
        $this->assertSame('Edited?', $second->content['sections'][0]['groups'][0]['questions'][0]['prompt']);
    }

    public function test_choose_two_takes_two_numbers_and_word_bank_answers_become_letters(): void
    {
        $compiled = $this->compile(Skill::Reading, ['sections' => [['groups' => [
            ['type' => 'multiple_choice', 'data' => ['questions' => [['prompt' => 'Which TWO?', 'options' => [
                ['text' => 'a', 'correct' => true], ['text' => 'b'], ['text' => 'c', 'correct' => true],
            ]]]]],
            ['type' => 'gap_fill', 'data' => ['text' => 'It was [[day]].', 'word_bank' => ['night', 'day']]],
        ]]]]);

        [$mc, $gap] = $compiled->content['sections'][0]['groups'];
        $this->assertSame([1, 2], $mc['questions'][0]['numbers']);
        $this->assertSame(2, $mc['questions'][0]['select']);
        $this->assertSame(3, $gap['firstNumber']);
        $this->assertSame([['key' => 'A', 'text' => 'night'], ['key' => 'B', 'text' => 'day']], $gap['options']);
        $this->assertSame(['b'], $compiled->answerKey[$gap['questions'][0]['id']]);
    }

    public function test_table_cells_allow_alternatives_inside_gaps(): void
    {
        $compiled = $this->compile(Skill::Listening, ['parts' => [['groups' => [
            ['type' => 'table_completion', 'data' => ['headers' => 'A | B', 'rows' => ['x | [[12|twelve]] slides', 'y | [[Friday]]']]],
        ]]]]);
        $group = $compiled->content['parts'][0]['groups'][0];

        $this->assertSame(['A', 'B'], $group['headers']);
        $this->assertCount(2, $group['rows'][0]);
        $this->assertSame([['12', 'twelve'], ['friday']], array_values($compiled->answerKey));
    }

    public function test_saving_a_test_compiles_it(): void
    {
        $test = Test::create([
            'skill' => Skill::Writing, 'title' => 'W', 'duration_seconds' => 2400,
            'form' => ['tasks' => [['number' => 2, 'kind' => 'essay', 'prompt' => 'Discuss.']]],
        ]);

        $this->assertSame('task2', $test->writingTasks());
        $this->assertSame(250, $test->content['tasks'][0]['minWords']);
        $this->assertNotEmpty($test->form['tasks'][0]['id']);
    }
}
