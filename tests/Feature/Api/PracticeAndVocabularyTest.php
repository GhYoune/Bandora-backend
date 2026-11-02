<?php

namespace Tests\Feature\Api;

use App\Enums\Skill;
use App\Models\Deck;
use App\Models\Pack;
use App\Models\Test;
use App\Services\PaymentService;
use Tests\TestCase;

/** Spelling tags, custom practice, vocabulary decks, weak points and payment channels. */
class PracticeAndVocabularyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    /** The question id whose first accepted answer is $answer. */
    private function questionFor(Test $test, string $answer): string
    {
        return collect($test->answer_key)->search(fn (array $accepted) => $accepted[0] === $answer);
    }

    private function essay(): string
    {
        return str_repeat('Many young people move to cities because there are more jobs and better universities. ', 25);
    }

    public function test_review_tags_misspelled_answers_but_still_marks_them_wrong(): void
    {
        $this->student();
        $test = $this->firstOf(Skill::Listening, free: true);
        $id = $this->postJson("/api/v1/tests/{$test->id}/attempts")->json('id');

        $answers = [
            $this->questionFor($test, 'mitchell') => 'Mitchel',       // 8 letters, 1 edit: spelling
            $this->questionFor($test, 'swimming') => 'swiming',       // spelling
            $this->questionFor($test, 'newspaper') => 'magazine',     // just wrong
            $this->questionFor($test, 'ef4 7tr') => 'EF4 7TS',        // has digits: never a spelling error
        ];
        $this->postJson("/api/v1/attempts/{$id}/submit", ['answers' => $answers])->assertOk()->assertJsonPath('rawScore', 0);

        $result = $this->getJson("/api/v1/attempts/{$id}/result")->assertOk()->json();
        $review = collect($result['review'])->keyBy('questionId');
        $this->assertTrue($review[array_keys($answers)[0]]['spellingError']);
        $this->assertFalse($review[array_keys($answers)[0]]['isCorrect']);
        $this->assertTrue($review[array_keys($answers)[1]]['spellingError']);
        $this->assertFalse($review[array_keys($answers)[2]]['spellingError']);
        $this->assertFalse($review[array_keys($answers)[3]]['spellingError']);
        $this->assertSame(
            [['userAnswer' => 'Mitchel', 'correctAnswer' => 'mitchell'], ['userAnswer' => 'swiming', 'correctAnswer' => 'swimming']],
            array_map(fn ($w) => ['userAnswer' => $w['userAnswer'], 'correctAnswer' => $w['correctAnswer']], $result['misspelledWords']),
        );
        $this->assertArrayNotHasKey('misspeltAnswer', $result['review'][0]);
    }

    public function test_custom_writing_is_marked_but_excluded_from_the_band(): void
    {
        $user = $this->student();

        $attempt = $this->postJson('/api/v1/custom-practice', [
            'skill' => 'writing', 'taskNumber' => 2, 'timed' => false,
            'prompt' => 'Some people think young people should leave home at 18. To what extent do you agree?',
        ])->assertCreated()->json();

        $this->assertTrue($attempt['custom']);
        $this->assertFalse($attempt['timed']);
        $this->assertSame(0, $attempt['remainingSeconds']);
        $this->assertSame('Your own task · Writing Task 2', $attempt['title']);
        $task = $attempt['content']['tasks'][0];
        $this->assertSame([2, 'essay', 250], [$task['number'], $task['kind'], $task['minWords']]);

        $this->postJson("/api/v1/attempts/{$attempt['id']}/submit", ['answers' => [$task['id'] => $this->essay()]])->assertOk();
        $this->assertSame(0, $user->fresh()->credits);
        $this->getJson("/api/v1/attempts/{$attempt['id']}/result")->assertJsonPath('status', 'completed')->assertJsonPath('custom', true);

        // In history, not in the band, not in the library or the teacher's list.
        $this->getJson('/api/v1/attempts')->assertJsonPath('data.0.custom', true);
        $this->getJson('/api/v1/dashboard')->assertJsonPath('skills.2.band', null);
        $this->assertNotContains($attempt['testId'], array_column($this->getJson('/api/v1/tests?skill=writing')->json(), 'id'));
        $this->getJson("/api/v1/tests/{$attempt['testId']}")->assertNotFound();
        $this->assertSame(0, Test::catalog()->whereKey($attempt['testId'])->count());
    }

    public function test_timed_custom_writing_gets_the_task_time(): void
    {
        $this->student();
        $attempt = $this->postJson('/api/v1/custom-practice', [
            'skill' => 'writing', 'taskNumber' => 1, 'timed' => true,
            'prompt' => 'You recently bought a jacket online but it arrived damaged. Write a letter to the shop.',
        ])->assertCreated()->json();

        $this->assertTrue($attempt['timed']);
        $this->assertSame(1200, $attempt['remainingSeconds']);
        $this->assertSame('letter', $attempt['content']['tasks'][0]['kind']);
    }

    public function test_custom_speaking_parts(): void
    {
        $this->student(['credits' => 3]);

        $part2 = $this->postJson('/api/v1/custom-practice', [
            'skill' => 'speaking', 'part' => 2,
            'cueCard' => ['topic' => 'Describe a place you like to visit.', 'bullets' => ['where it is', 'how often you go'], 'closing' => 'and explain why you like it.'],
        ])->assertCreated()->json();
        $card = $part2['content']['parts'][0];
        $this->assertSame(['cue_card', 60, 120, 20, []], [$card['kind'], $card['prepSeconds'], $card['speakSeconds'], $card['followUpSeconds'], $card['followUps']]);
        $this->assertSame(['where it is', 'how often you go'], $card['bullets']);

        $part1 = $this->postJson('/api/v1/custom-practice', [
            'skill' => 'speaking', 'part' => 1, 'questions' => ['Do you work or study?', 'What do you like about your job?'],
        ])->assertCreated()->json();
        $this->assertSame('interview', $part1['content']['parts'][0]['kind']);
        $this->assertCount(2, $part1['content']['parts'][0]['questions']);
        $this->assertSame(1, $part1['content']['parts'][0]['number']);
    }

    public function test_custom_practice_validation_and_credits(): void
    {
        $this->student(['credits' => 0]);
        $this->postJson('/api/v1/custom-practice', ['skill' => 'writing', 'taskNumber' => 2, 'timed' => true, 'prompt' => 'Too short'])
            ->assertUnprocessable()->assertJsonValidationErrors('prompt');
        $this->postJson('/api/v1/custom-practice', ['skill' => 'speaking', 'part' => 3, 'questions' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('questions');
        $this->postJson('/api/v1/custom-practice', ['skill' => 'speaking', 'part' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('cueCard');

        $this->postJson('/api/v1/custom-practice', ['skill' => 'speaking', 'part' => 1, 'questions' => ['Where do you live?']])
            ->assertForbidden()->assertJson(['code' => 'no_credits']);
    }

    public function test_decks_unlock_like_tests_and_track_progress(): void
    {
        $user = $this->student();
        $decks = $this->getJson('/api/v1/decks')->assertOk()->json();
        $this->assertSame(['free', 'locked', 'locked'], array_column($decks, 'access'));
        $this->assertSame([8, 0], [$decks[0]['cardCount'], $decks[0]['knownCount']]);

        $this->getJson("/api/v1/decks/{$decks[1]['id']}")->assertForbidden()->assertJson(['code' => 'pack_required']);

        $deck = $this->getJson("/api/v1/decks/{$decks[0]['id']}")->assertOk()->json();
        $this->assertSame('applicant', $deck['cards'][0]['word']);
        $this->assertSame('candidat', $deck['cards'][0]['translationFr']);
        $this->assertSame('new', $deck['cards'][0]['status']);

        $this->putJson("/api/v1/cards/{$deck['cards'][0]['id']}/progress", ['status' => 'known'])->assertNoContent();
        $this->putJson("/api/v1/cards/{$deck['cards'][1]['id']}/progress", ['status' => 'learning'])->assertNoContent();
        $this->putJson("/api/v1/cards/{$deck['cards'][1]['id']}/progress", ['status' => 'maybe'])->assertUnprocessable();
        $again = $this->getJson("/api/v1/decks/{$deck['id']}")->json();
        $this->assertSame(['known', 'learning', 'new'], array_column(array_slice($again['cards'], 0, 3), 'status'));
        $this->assertSame(1, $this->getJson('/api/v1/decks')->json('0.knownCount'));

        $this->deleteJson("/api/v1/decks/{$deck['id']}/progress")->assertNoContent();
        $this->assertSame(0, $this->getJson('/api/v1/decks')->json('0.knownCount'));

        // A pack unlocks the rest.
        app(PaymentService::class)->activate($user, Pack::orderBy('sort')->first());
        $this->actAs($user);
        $this->assertSame(['free', 'included', 'included'], array_column($this->getJson('/api/v1/decks')->json(), 'access'));

        Deck::query()->update(['is_published' => false]);
        $this->assertSame([], $this->getJson('/api/v1/decks')->json());
    }

    public function test_insights_gather_weak_points_across_attempts(): void
    {
        $this->student(['credits' => 2]);
        $this->assertSame(
            ['questionTypes' => [], 'misspelledWords' => [], 'writing' => ['attemptsCounted' => 0, 'criteria' => [], 'errorCategories' => []], 'speaking' => ['attemptsCounted' => 0, 'criteria' => [], 'errorCategories' => []]],
            $this->getJson('/api/v1/insights')->assertOk()->json(),
        );

        $listening = $this->firstOf(Skill::Listening, free: true);
        foreach (['Mitchel', 'Mitchal'] as $spelling) {
            $id = $this->postJson("/api/v1/tests/{$listening->id}/attempts")->json('id');
            $this->postJson("/api/v1/attempts/{$id}/submit", ['answers' => [$this->questionFor($listening, 'mitchell') => $spelling]]);
        }
        $writing = $this->firstOf(Skill::Writing, free: true);
        $id = $this->postJson("/api/v1/tests/{$writing->id}/attempts")->json('id');
        $this->postJson("/api/v1/attempts/{$id}/submit", ['answers' => [$writing->content['tasks'][0]['id'] => $this->essay().' alot of people dont agree.']]);

        $insights = $this->getJson('/api/v1/insights')->json();
        $types = collect($insights['questionTypes'])->keyBy('type');
        $this->assertSame(0, $types['gap_fill']['correct']);
        $this->assertSame(0, $insights['questionTypes'][0]['correct']); // weakest first
        $this->assertSame([['correctAnswer' => 'mitchell', 'userAnswer' => 'Mitchal', 'count' => 2]], $insights['misspelledWords']);
        $this->assertSame(1, $insights['writing']['attemptsCounted']);
        $this->assertCount(4, $insights['writing']['criteria']);
        $this->assertNotEmpty($insights['writing']['errorCategories']);
        $bands = array_column($insights['writing']['criteria'], 'averageBand');
        $this->assertSame($bands, collect($bands)->sort()->values()->all());
    }

    public function test_payment_offers_telegram_and_phone_when_configured(): void
    {
        $this->student();
        $pack = Pack::orderBy('sort')->first();

        $payment = $this->postJson('/api/v1/payment-requests', ['packId' => $pack->id, 'whatsapp' => '+213550112233'])->assertCreated()->json();
        $this->assertNull($payment['telegramUrl']);
        $this->assertNull($payment['phone']);
        $this->assertStringContainsString($payment['reference'], $payment['message']);
        $this->assertSame($payment['message'], urldecode(explode('?text=', $payment['whatsappUrl'])[1]));

        config(['bandora.teacher_telegram' => '@bandora_lydia', 'bandora.teacher_phone' => '+213550000000']);
        $this->getJson('/api/v1/payment-requests')
            ->assertJsonPath('0.telegramUrl', 'https://t.me/bandora_lydia')
            ->assertJsonPath('0.phone', '+213550000000');
    }

    public function test_follow_up_answer_time_comes_from_the_test(): void
    {
        $speaking = $this->firstOf(Skill::Speaking, free: false);
        $card = collect($speaking->content['parts'])->firstWhere('kind', 'cue_card');
        $this->assertSame(20, $card['followUpSeconds']);
        $this->assertNotEmpty($card['followUps']);
    }
}
