<?php

namespace Tests\Feature\Api;

use App\Enums\Skill;
use App\Jobs\MarkAttempt;
use App\Models\Attempt;
use App\Models\Pack;
use App\Models\User;
use App\Services\Marking\AiMarker;
use App\Services\PaymentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttemptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    private function withPack(User $user): User
    {
        app(PaymentService::class)->activate($user, Pack::orderBy('sort')->first());
        $this->actAs($user);

        return $user->fresh();
    }

    /** Correct answers for every question, in the shape the frontend sends. */
    private function perfectAnswers(Attempt $attempt): array
    {
        $answers = [];
        foreach ($attempt->test->answer_key as $id => $accepted) {
            $answers[$id] = count($accepted) > 1 && preg_match('/^[a-h]$/', $accepted[0]) && preg_match('/^[a-h]$/', $accepted[1])
                ? array_map('strtoupper', $accepted)        // "Choose TWO": both letters
                : (preg_match('/^[a-h]$/', $accepted[0]) ? strtoupper($accepted[0]) : $accepted[0]);
        }

        return $answers;
    }

    public function test_library_lists_tests_with_access_for_a_free_student(): void
    {
        $this->student();

        $tests = $this->getJson('/api/v1/tests?skill=reading')->assertOk()->json();
        $this->assertCount(3, $tests);
        $this->assertSame(['free', 'locked', 'locked'], array_column($tests, 'access'));
        $this->assertSame(40, $tests[0]['questionCount']);
        $this->assertArrayNotHasKey('creditCost', $tests[0]);

        $writing = $this->getJson('/api/v1/tests?skill=writing')->json();
        $this->assertSame(['task2', 'both', 'task1'], array_column($writing, 'writingTasks'));
        $this->assertSame([1, 2, 1], array_column($writing, 'creditCost'));
    }

    public function test_locked_test_needs_a_pack(): void
    {
        $this->student();
        $locked = $this->firstOf(Skill::Reading, free: false);

        $this->postJson("/api/v1/tests/{$locked->id}/attempts")
            ->assertForbidden()->assertJson(['code' => 'pack_required']);
    }

    public function test_attempt_never_exposes_answers_and_resumes_the_open_one(): void
    {
        $this->student();
        $test = $this->firstOf(Skill::Listening, free: true);

        $first = $this->postJson("/api/v1/tests/{$test->id}/attempts")->assertCreated()->json();
        $this->assertSame('in_progress', $first['status']);
        $this->assertSame(2400, $first['remainingSeconds']);
        $this->assertSame(['answered' => 0, 'total' => 40], $first['progress']);
        // Gap answers are replaced by ids; multiple-choice options carry no "correct" flag.
        $json = json_encode($first['content']);
        $this->assertStringContainsString('Name: Sarah [[', $json);
        $this->assertDoesNotMatchRegularExpression('/Mitchell|"correct"|answer_key/', $json);
        $this->assertEquals((object) [], (object) $first['answers']);

        $again = $this->postJson("/api/v1/tests/{$test->id}/attempts")->assertOk()->json();
        $this->assertSame($first['id'], $again['id']);
    }

    public function test_autosave_then_submit_marks_reading(): void
    {
        $this->student();
        $test = $this->firstOf(Skill::Reading, free: true);
        $id = $this->postJson("/api/v1/tests/{$test->id}/attempts")->json('id');
        $attempt = Attempt::find($id);
        $answers = $this->perfectAnswers($attempt);

        // Getting 30 right → band 6.0 on the General Training Reading table.
        $partial = array_slice($answers, 0, 30, preserve_keys: true);
        $this->patchJson("/api/v1/attempts/{$id}", ['answers' => $partial, 'remainingSeconds' => 1200, 'position' => ['section' => 2]])
            ->assertOk()->assertJsonStructure(['savedAt']);
        $this->getJson("/api/v1/attempts/{$id}")->assertJsonPath('remainingSeconds', 1200)->assertJsonPath('progress.answered', 30);

        $this->getJson("/api/v1/attempts/{$id}/result")->assertStatus(409)->assertJson(['code' => 'attempt_open']);

        $this->postJson("/api/v1/attempts/{$id}/submit", ['answers' => $partial])
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('rawScore', 30)->assertJsonPath('band', 6);

        $result = $this->getJson("/api/v1/attempts/{$id}/result")->assertOk()->json();
        $this->assertSame(['reading', 30, 40, 6], [$result['skill'], $result['rawScore'], $result['maxScore'], $result['band']]);
        $this->assertCount(40, $result['review']);
        $this->assertSame(range(1, 40), array_column($result['review'], 'number'));
        $this->assertSame(40, array_sum(array_column($result['breakdown'], 'total')));

        $this->patchJson("/api/v1/attempts/{$id}", ['answers' => []])->assertStatus(409)->assertJson(['code' => 'attempt_closed']);
    }

    public function test_listening_full_marks_and_practice_conditions(): void
    {
        $this->student();
        $test = $this->firstOf(Skill::Listening, free: true);
        $id = $this->postJson("/api/v1/tests/{$test->id}/attempts", ['listeningPlays' => 2])->json('id');
        $answers = $this->perfectAnswers(Attempt::find($id));

        $this->postJson("/api/v1/attempts/{$id}/submit", ['answers' => $answers])->assertJsonPath('rawScore', 40)->assertJsonPath('band', 9);
        $this->getJson("/api/v1/attempts/{$id}/result")->assertJsonPath('practiceConditions', true);

        // Heard twice: excluded from the estimated band.
        $this->getJson('/api/v1/dashboard')->assertJsonPath('skills.0', ['skill' => 'listening', 'band' => null, 'testsCounted' => 0]);
    }

    public function test_choose_two_counts_two_marks(): void
    {
        $this->student();
        $test = $this->firstOf(Skill::Listening, free: true);
        $id = $this->postJson("/api/v1/tests/{$test->id}/attempts")->json('id');
        $q = collect($test->content['parts'][1]['groups'])->firstWhere('questions.0.select', 2)['questions'][0];

        $result = $this->postJson("/api/v1/attempts/{$id}/submit", ['answers' => [$q['id'] => ['A', 'B']]])->json();
        $this->assertSame(1, $result['rawScore']); // A right, B wrong

        $review = collect($this->getJson("/api/v1/attempts/{$id}/result")->json('review'))->whereIn('number', [16, 17])->values();
        $this->assertSame([true, false], $review->pluck('isCorrect')->all());
        $this->assertSame(['B', 'D'], [$review[1]['userAnswer'], $review[1]['correctAnswers'][0]]);
    }

    public function test_writing_charges_credits_and_ai_marks_it(): void
    {
        $user = $this->student();
        $test = $this->firstOf(Skill::Writing, free: true); // Task 2 only, 1 credit
        $id = $this->postJson("/api/v1/tests/{$test->id}/attempts")->assertCreated()->json('id');
        $taskId = $test->content['tasks'][0]['id'];
        $essay = str_repeat('Many young people move to cities because there are more jobs and better universities. ', 25);

        $this->postJson("/api/v1/attempts/{$id}/submit", ['answers' => [$taskId => $essay]])
            ->assertOk()->assertJsonPath('status', 'marking'); // the queue is sync in tests, but the response is from before the job

        $this->assertSame(0, $user->fresh()->credits);
        $result = $this->getJson("/api/v1/attempts/{$id}/result")->assertOk()->json();
        $this->assertSame('completed', $result['status']);
        $this->assertCount(4, $result['tasks'][0]['criteria']);
        $this->assertSame('task_response', $result['tasks'][0]['criteria'][0]['key']);
        $this->assertSame(350, $result['tasks'][0]['wordCount']); // 14 words x 25
        $this->assertIsFloat($result['band'] + 0.0);
    }

    public function test_writing_without_credits_is_refused_but_answers_are_kept(): void
    {
        $user = $this->withPack($this->student());
        $test = $this->firstOf(Skill::Writing, free: false); // both tasks, 2 credits
        $id = $this->postJson("/api/v1/tests/{$test->id}/attempts")->json('id');
        $user->forceFill(['credits' => 1])->save();
        $taskId = $test->content['tasks'][0]['id'];

        $this->postJson("/api/v1/attempts/{$id}/submit", ['answers' => [$taskId => 'Dear Sir or Madam,']])
            ->assertForbidden()->assertJson(['code' => 'no_credits']);

        $this->assertSame('Dear Sir or Madam,', Attempt::find($id)->answers[$taskId]);
        $this->assertSame(1, $user->fresh()->credits);
    }

    public function test_marking_failure_refunds_credits(): void
    {
        $user = $this->student();
        $test = $this->firstOf(Skill::Writing, free: true);
        $id = $this->postJson("/api/v1/tests/{$test->id}/attempts")->json('id');
        $this->app->bind(AiMarker::class, fn () => new class implements AiMarker
        {
            public function markWriting(array $task, string $text): array
            {
                throw new \RuntimeException('AI down');
            }

            public function markSpeaking(array $content, Collection $recordings): array
            {
                return [];
            }
        });

        try {
            $this->postJson("/api/v1/attempts/{$id}/submit", ['answers' => [$test->content['tasks'][0]['id'] => 'An essay.']]);
        } catch (\RuntimeException) {
            // the sync queue rethrows; on a real queue the job retries, then failed() runs
        }
        (new MarkAttempt(Attempt::find($id)))->failed(new \RuntimeException('AI down'));

        $this->assertSame(1, $user->fresh()->credits);
        $this->getJson("/api/v1/attempts/{$id}/result")->assertJsonPath('status', 'marking_failed');
    }

    public function test_speaking_upload_charge_per_part_and_signed_playback(): void
    {
        Storage::fake('local');
        $user = $this->withPack($this->student());
        $test = $this->firstOf(Skill::Speaking, free: false);
        $id = $this->postJson("/api/v1/tests/{$test->id}/attempts")->json('id');
        $part1 = $test->content['parts'][0]['questions'];
        $before = $user->fresh()->credits;

        foreach ([$part1[0]['id'], $part1[1]['id']] as $questionId) {
            $this->post("/api/v1/attempts/{$id}/recordings", [
                'questionId' => $questionId,
                'durationSeconds' => 30,
                'audio' => UploadedFile::fake()->create('a.webm', 40, 'audio/webm'),
            ], ['Accept' => 'application/json'])->assertCreated()->assertJsonStructure(['id', 'questionId', 'durationSeconds', 'url']);
        }
        // Recording again replaces the answer.
        $this->post("/api/v1/attempts/{$id}/recordings", [
            'questionId' => $part1[0]['id'], 'durationSeconds' => 35, 'audio' => UploadedFile::fake()->create('b.webm', 40, 'audio/webm'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(2, Attempt::find($id)->recordings()->count());

        $this->postJson("/api/v1/attempts/{$id}/submit", [])->assertOk();
        $this->assertSame($before - 1, $user->fresh()->credits); // only Part 1 answered

        $result = $this->getJson("/api/v1/attempts/{$id}/result")->json();
        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['criteria'][3]['indicative']);
        $url = $result['parts'][0]['items'][0]['audioUrl'];
        $this->get($url)->assertOk();
        $this->get(preg_replace('/signature=\w+/', 'signature=forged', $url))->assertForbidden();
    }

    public function test_students_cannot_see_each_others_attempts(): void
    {
        $this->student();
        $test = $this->firstOf(Skill::Reading, free: true);
        $id = $this->postJson("/api/v1/tests/{$test->id}/attempts")->json('id');

        $this->student();
        $this->getJson("/api/v1/attempts/{$id}")->assertNotFound();
        $this->patchJson("/api/v1/attempts/{$id}", ['answers' => []])->assertNotFound();
    }

    public function test_history_is_paginated_and_filterable(): void
    {
        $this->student();
        $reading = $this->firstOf(Skill::Reading, free: true);
        $listening = $this->firstOf(Skill::Listening, free: true);
        $this->postJson("/api/v1/tests/{$reading->id}/attempts");
        $this->postJson("/api/v1/tests/{$listening->id}/attempts");

        $this->getJson('/api/v1/attempts')->assertJsonPath('meta', ['page' => 1, 'lastPage' => 1, 'total' => 2]);
        $this->getJson('/api/v1/attempts?skill=listening')->assertJsonCount(1, 'data')->assertJsonPath('data.0.skill', 'listening');
    }
}
