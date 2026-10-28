<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentStatus;
use App\Models\Pack;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentService;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    private Pack $pack;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        $this->pack = Pack::where('name', '3 months')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    public function test_packs_are_public(): void
    {
        $this->getJson('/api/v1/packs')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('1', [
                'id' => $this->pack->id, 'name' => '3 months', 'durationDays' => 90, 'priceDzd' => 8000,
                'credits' => 40, 'highlighted' => true, 'features' => $this->pack->features,
            ]);
    }

    public function test_request_returns_a_prefilled_whatsapp_link(): void
    {
        $user = $this->student();

        $payment = $this->postJson('/api/v1/payment-requests', ['packId' => $this->pack->id, 'whatsapp' => '+213 550 11 22 33'])
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('amountDzd', 8000)
            ->json();

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}$/', $payment['reference']);
        $text = urldecode(explode('?text=', $payment['whatsappUrl'])[1]);
        $this->assertStringStartsWith('https://wa.me/213550000000?text=', $payment['whatsappUrl']);
        $this->assertStringContainsString($user->email, $text);
        $this->assertStringContainsString($payment['reference'], $text);
        $this->assertSame('+213550112233', $user->fresh()->whatsapp);

        $this->getJson('/api/v1/me')->assertJsonPath('pendingPayment.reference', $payment['reference']);
        $this->postJson('/api/v1/payment-requests', ['packId' => $this->pack->id, 'whatsapp' => '+213550112233'])
            ->assertStatus(409)->assertJson(['code' => 'payment_pending']);
    }

    public function test_unverified_email_cannot_buy(): void
    {
        $this->student(['email_verified_at' => null]);

        $this->postJson('/api/v1/payment-requests', ['packId' => $this->pack->id, 'whatsapp' => '+213550112233'])
            ->assertForbidden()->assertJson(['code' => 'email_unverified']);
    }

    public function test_approval_activates_the_pack_and_unlocks_tests(): void
    {
        $user = $this->student();
        $id = $this->postJson('/api/v1/payment-requests', ['packId' => $this->pack->id, 'whatsapp' => '+213550112233'])->json('id');

        app(PaymentService::class)->approve(PaymentRequest::find($id), $this->admin());
        $this->actAs($user); // the next request loads the updated account

        $me = $this->getJson('/api/v1/me')->json();
        $this->assertSame('paid', $me['access']['tier']);
        $this->assertSame(1 + 40, $me['access']['credits']); // the free credit is kept
        $this->assertNull($me['pendingPayment']);
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, strtotime($me['access']['pack']['expiresAt']), 5);
        $this->assertSame(['free', 'included', 'included'], array_column($this->getJson('/api/v1/tests?skill=reading')->json(), 'access'));
    }

    public function test_extending_adds_days_after_the_current_end(): void
    {
        $user = $this->student();
        $service = app(PaymentService::class);
        $service->activate($user, $this->pack);
        $firstEnd = $user->fresh()->pack_expires_at;

        $service->activate($user->fresh(), Pack::where('name', '1 month')->first());

        $this->assertEquals($firstEnd->copy()->addDays(30)->timestamp, $user->fresh()->pack_expires_at->timestamp);
        $this->assertSame(1 + 40 + 10, $user->fresh()->credits);
    }

    public function test_expired_pack_locks_tests_and_zeroes_credits(): void
    {
        $user = $this->student(['credits' => 12, 'pack_name' => '1 month', 'pack_started_at' => now()->subDays(40), 'pack_expires_at' => now()->subDays(10)]);

        $this->getJson('/api/v1/me')->assertJsonPath('access.tier', 'expired')->assertJsonPath('access.credits', 0);
        $this->assertSame(['free', 'locked', 'locked'], array_column($this->getJson('/api/v1/tests?skill=reading')->json(), 'access'));

        // A new pack starts today, without the old credits.
        app(PaymentService::class)->activate($user->fresh(), $this->pack);
        $this->assertSame(40, $user->fresh()->credits);
        $this->assertTrue($user->fresh()->pack_started_at->isToday());
    }

    public function test_cancel_and_double_decisions(): void
    {
        $this->student();
        $id = $this->postJson('/api/v1/payment-requests', ['packId' => $this->pack->id, 'whatsapp' => '+213550112233'])->json('id');

        $this->deleteJson("/api/v1/payment-requests/{$id}")->assertNoContent();
        $this->assertSame(PaymentStatus::Cancelled, PaymentRequest::find($id)->status);
        $this->deleteJson("/api/v1/payment-requests/{$id}")->assertStatus(409)->assertJson(['code' => 'already_decided']);
        $this->getJson('/api/v1/payment-requests')->assertJsonPath('0.status', 'cancelled');
    }
}
