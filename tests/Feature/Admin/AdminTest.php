<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentStatus;
use App\Enums\Skill;
use App\Filament\Resources\Decks\Pages\CreateDeck;
use App\Filament\Resources\PaymentRequests\Pages\ManagePaymentRequests;
use App\Filament\Resources\Tests\Pages\CreateTest;
use App\Filament\Resources\Tests\Pages\EditTest;
use App\Filament\Resources\Tests\Pages\ListTests;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Deck;
use App\Models\Pack;
use App\Models\Test;
use App\Models\User;
use App\Services\PaymentService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        $this->admin = User::where('is_admin', true)->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_students_cannot_open_the_admin(): void
    {
        $this->actingAs(User::firstWhere('email', 'student@demo.dz'));
        $this->get('/admin')->assertForbidden();
    }

    public function test_admin_pages_render(): void
    {
        foreach (['/admin', '/admin/payment-requests', '/admin/users', '/admin/tests', '/admin/packs', '/admin/tests/create', '/admin/decks', '/admin/decks/create'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/admin/tests/'.Test::first()->id.'/edit')->assertOk();
        $this->get('/admin/decks/'.Deck::first()->id.'/edit')->assertOk()->assertSee('applicant');
    }

    public function test_students_custom_tasks_stay_out_of_the_test_list(): void
    {
        $student = User::firstWhere('email', 'student@demo.dz');
        $custom = Test::create([
            'user_id' => $student->id, 'skill' => Skill::Writing, 'title' => 'Your own task · Writing Task 2',
            'duration_seconds' => 0, 'form' => ['tasks' => [['number' => 2, 'prompt' => 'Some prompt that is long enough.']]],
        ]);

        Livewire::test(ListTests::class)
            ->assertCanSeeTableRecords([Test::catalog()->first()])
            ->assertCanNotSeeTableRecords([$custom]);
    }

    public function test_teacher_creates_a_deck_with_cards(): void
    {
        Livewire::test(CreateDeck::class)
            ->fillForm([
                'title' => 'Health', 'topic' => 'Health', 'is_free' => true, 'is_published' => true,
                'cards' => [
                    ['word' => 'symptom', 'definition' => 'A sign of an illness.', 'example' => 'A cough is a common symptom.', 'translation_fr' => 'symptôme'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $deck = Deck::firstWhere('title', 'Health');
        $this->assertSame(['symptom'], $deck->cards()->pluck('word')->all());
        $this->assertSame('symptôme', $deck->cards()->first()->translation_fr);
    }

    public function test_teacher_approves_a_payment_from_the_inbox(): void
    {
        $student = User::factory()->create(['email_verified_at' => now()]);
        $payment = app(PaymentService::class)->request($student, Pack::firstWhere('name', '1 month'), '+213550112233');

        Livewire::test(ManagePaymentRequests::class)
            ->assertCanSeeTableRecords([$payment])
            ->callAction(TestAction::make('approve')->table($payment))
            ->assertNotified('Pack activated');

        $this->assertSame(PaymentStatus::Approved, $payment->fresh()->status);
        $this->assertSame($this->admin->id, $payment->fresh()->decided_by);
        $this->assertTrue($student->fresh()->hasActivePack());
        $this->assertSame(10, $student->fresh()->credits);
    }

    public function test_payments_cannot_be_created_by_hand(): void
    {
        Livewire::test(ManagePaymentRequests::class)->assertActionDoesNotExist('create');
    }

    public function test_teacher_rejects_a_payment(): void
    {
        $student = User::factory()->create(['email_verified_at' => now()]);
        $payment = app(PaymentService::class)->request($student, Pack::first(), '+213550112233');

        Livewire::test(ManagePaymentRequests::class)->callAction(TestAction::make('reject')->table($payment));

        $this->assertSame(PaymentStatus::Rejected, $payment->fresh()->status);
        $this->assertFalse($student->fresh()->hasActivePack());
    }

    public function test_activate_a_pack_from_the_student_page(): void
    {
        $student = User::factory()->create();

        Livewire::test(ViewUser::class, ['record' => $student->id])
            ->callAction('activatePack', ['pack_id' => Pack::firstWhere('name', '6 months')->id])
            ->assertNotified();

        $this->assertSame('6 months', $student->fresh()->pack_name);
        $this->assertSame(100, $student->fresh()->credits);
    }

    public function test_teacher_builds_a_reading_test_with_the_form(): void
    {
        Livewire::test(CreateTest::class)
            ->fillForm([
                'skill' => Skill::Reading->value,
                'title' => 'Reading Test 9',
                'duration_seconds' => 3600,
                'is_published' => false, // a 2-question draft cannot be published
                'form.sections' => [[
                    'passages' => [[
                        'title' => 'Library notice',
                        'paragraphs' => [['label' => null, 'text' => 'Books can be kept for three weeks.']],
                    ]],
                    'groups' => [
                        ['type' => 'gap_fill', 'data' => ['layout' => 'notes', 'text' => '- Books can be kept for [[three weeks|3 weeks]]']],
                        ['type' => 'true_false_not_given', 'data' => ['variant' => 'tfng', 'questions' => [
                            ['statement' => 'DVDs cost money.', 'answer' => 'not given'],
                        ]]],
                    ],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $test = Test::firstWhere('title', 'Reading Test 9');
        $this->assertSame(2, $test->question_count);
        $groups = $test->content['sections'][0]['groups'];
        $this->assertSame(['gap_fill', 'true_false_not_given'], array_column($groups, 'type'));
        $this->assertSame([2, 2], [$groups[1]['firstNumber'], $groups[1]['lastNumber']]);
        $this->assertContains(['three weeks', '3 weeks'], $test->answer_key);
        $this->assertNotEmpty($test->form['sections'][0]['groups'][0]['data']['id'] ?? null, 'ids are written back into the form');
    }

    public function test_incomplete_tests_cannot_be_published(): void
    {
        $test = $this->firstOf(Skill::Listening, free: true);
        $this->assertSame([], $test->publishProblems());

        $form = $test->form;
        $form['parts'][0]['audio'] = null;
        $test->update(['is_published' => false, 'form' => $form]);
        $this->assertSame(['Part 1 has no recording.'], $test->fresh()->publishProblems());

        $this->expectException(ValidationException::class);
        $test->fresh()->update(['is_published' => true]);
    }

    public function test_editing_a_test_keeps_question_ids(): void
    {
        $test = Test::where('skill', Skill::Writing)->first();
        $taskId = $test->content['tasks'][0]['id'];

        Livewire::test(EditTest::class, ['record' => $test->id])
            ->fillForm(['title' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed', $test->fresh()->title);
        $this->assertSame($taskId, $test->fresh()->content['tasks'][0]['id']);
    }

    public function test_teacher_fills_a_listening_part_in_its_own_tab(): void
    {
        Livewire::test(CreateTest::class)
            ->fillForm(['skill' => Skill::Listening->value])
            ->assertSee('Part 4')
            ->fillForm([
                'title' => 'Listening Test 9',
                'duration_seconds' => 2400,
                'form.parts.1.groups' => [
                    ['type' => 'short_answer', 'data' => ['questions' => [['prompt' => 'Where is the meeting?', 'answer' => 'library']]]],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $test = Test::firstWhere('title', 'Listening Test 9');
        // Part 1 stays as an empty slot before the filled Part 2; empty Parts 3 and 4 are dropped.
        $this->assertCount(2, $test->content['parts']);
        $this->assertSame(1, $test->question_count);
        $this->assertSame(1, $test->content['parts'][1]['groups'][0]['firstNumber']);
    }

    public function test_a_writing_test_with_only_task_2_keeps_it_in_the_task_2_tab(): void
    {
        $test = Test::create([
            'skill' => Skill::Writing, 'title' => 'Essay only', 'duration_seconds' => 2400,
            'form' => ['tasks' => [['number' => 2, 'prompt' => 'Some people think cities should ban cars.']]],
        ]);

        Livewire::test(EditTest::class, ['record' => $test->id])
            ->assertSchemaStateSet(['form.tasks.1.prompt' => 'Some people think cities should ban cars.'])
            ->fillForm(['form.tasks.1.prompt' => 'Some people think cities should ban all cars.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $tasks = $test->fresh()->content['tasks'];
        $this->assertCount(1, $tasks, 'the empty Task 1 tab is not saved');
        $this->assertSame(2, $tasks[0]['number']);
        $this->assertSame('Some people think cities should ban all cars.', $tasks[0]['prompt']);
    }

    public function test_saving_a_full_listening_test_from_the_tabs_changes_nothing(): void
    {
        $test = $this->firstOf(Skill::Listening, free: true);
        $before = [$test->content, $test->answer_key, $test->question_count];
        // The demo recordings and map point at frontend files; give the upload fields something to find.
        $disk = Storage::fake('public');
        $form = $test->form;
        array_walk_recursive($form, function ($value, $key) use ($disk) {
            if (in_array($key, ['audio', 'image'], true) && is_string($value)) {
                $disk->put(ltrim($value, '/'), 'x');
            }
        });

        Livewire::test(EditTest::class, ['record' => $test->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $test->refresh();
        $this->assertSame($before, [$test->content, $test->answer_key, $test->question_count]);
    }

    public function test_a_reading_text_can_be_a_scan_and_questions_can_have_a_picture(): void
    {
        $test = Test::create([
            'skill' => Skill::Reading, 'title' => 'Scanned', 'duration_seconds' => 3600,
            'form' => ['sections' => [[
                'passages' => [['title' => 'Gym timetable', 'paragraphs' => [
                    ['label' => 'A', 'image' => 'tests/images/page-1.jpg', 'image_alt' => 'Scanned timetable'],
                    ['text' => 'Classes are free for members.'],
                ]]],
                'groups' => [['type' => 'short_answer', 'data' => [
                    'picture' => 'tests/images/poster.png', 'picture_alt' => 'Gym poster',
                    'questions' => [['prompt' => 'What is free?', 'answer' => 'classes']],
                ]]],
            ]]],
        ]);

        $section = $test->content['sections'][0];
        [$scan, $typed] = $section['passages'][0]['paragraphs'];
        $this->assertSame('', $scan['text']);
        $this->assertStringEndsWith('tests/images/page-1.jpg', $scan['picture']['url']);
        $this->assertSame(['A', 'Scanned timetable'], [$scan['label'], $scan['picture']['alt']]);
        $this->assertNull($typed['picture']);
        $this->assertSame('Gym poster', $section['groups'][0]['picture']['alt']);
    }
}
