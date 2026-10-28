<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentStatus;
use App\Enums\Skill;
use App\Filament\Resources\PaymentRequests\Pages\ManagePaymentRequests;
use App\Filament\Resources\Tests\Pages\CreateTest;
use App\Filament\Resources\Tests\Pages\EditTest;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Pack;
use App\Models\Test;
use App\Models\User;
use App\Services\PaymentService;
use Filament\Actions\Testing\TestAction;
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
        foreach (['/admin', '/admin/payment-requests', '/admin/users', '/admin/tests', '/admin/packs', '/admin/tests/create'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/admin/tests/'.Test::first()->id.'/edit')->assertOk();
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
                'is_published' => true,
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
}
