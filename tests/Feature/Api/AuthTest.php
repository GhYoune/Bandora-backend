<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_register_logs_in_gives_free_credit_and_sends_verification(): void
    {
        Notification::fake();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/register', [
            'name' => 'Yacine Test',
            'email' => 'Yacine@Mail.dz',
            'password' => 'secret123',
            'passwordConfirmation' => 'secret123',
        ])
            ->assertCreated()
            ->assertJsonPath('email', 'yacine@mail.dz')
            ->assertJsonPath('emailVerified', false)
            ->assertJsonPath('access', ['tier' => 'free', 'pack' => null, 'credits' => 1])
            ->assertJsonPath('pendingPayment', null);

        Notification::assertSentTo(User::firstWhere('email', 'yacine@mail.dz'), VerifyEmail::class);
        $this->withHeaders($this->spa)->getJson('/api/v1/me')->assertOk()->assertJsonPath('name', 'Yacine Test');
    }

    public function test_register_validation_uses_contract_field_names(): void
    {
        User::factory()->create(['email' => 'taken@mail.dz']);

        $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'taken@mail.dz',
            'password' => 'short',
            'passwordConfirmation' => 'other',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'passwordConfirmation'])
            ->assertJsonPath('errors.email.0', 'An account with this email already exists.');
    }

    public function test_login_and_logout(): void
    {
        User::factory()->create(['email' => 'amina@mail.dz', 'password' => 'password']);

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'amina@mail.dz', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'AMINA@mail.dz', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['id', 'name', 'email', 'emailVerified', 'whatsapp', 'access' => ['tier', 'pack', 'credits'], 'pendingPayment']);

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->spa)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_password_reset_link_points_to_the_frontend_and_resets(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'amina@mail.dz']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'amina@mail.dz'])->assertOk();
        // Unknown emails get the same answer.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@mail.dz'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user, &$token) {
            $token = $n->token;
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, 'http://localhost:3000/reset-password?token=');
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'amina@mail.dz', 'password' => 'newpass123', 'passwordConfirmation' => 'newpass123',
        ])->assertOk();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'amina@mail.dz', 'password' => 'newpass123'])->assertOk();
    }

    public function test_verification_link_verifies_and_redirects_to_the_app(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->get($url)->assertRedirect('http://localhost:3000/app?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_profile_update_keeps_whatsapp_when_only_name_changes(): void
    {
        $user = $this->student(['whatsapp' => '+213550123456']);

        $this->patchJson('/api/v1/me', ['name' => 'New Name'])
            ->assertOk()->assertJsonPath('name', 'New Name')->assertJsonPath('whatsapp', '+213550123456');
        $this->patchJson('/api/v1/me', ['whatsapp' => '+213 661 22 33 44'])->assertOk()->assertJsonPath('whatsapp', '+213661223344');
        $this->patchJson('/api/v1/me', ['whatsapp' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('whatsapp');
        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_password_change_checks_the_current_password(): void
    {
        $this->student(['password' => bcrypt('password')]);

        $this->putJson('/api/v1/me/password', ['currentPassword' => 'nope', 'password' => 'newpass123', 'passwordConfirmation' => 'newpass123'])
            ->assertStatus(422)->assertJsonPath('errors.currentPassword.0', 'Your current password is incorrect.');
        $this->putJson('/api/v1/me/password', ['currentPassword' => 'password', 'password' => 'newpass123', 'passwordConfirmation' => 'newpass123'])
            ->assertNoContent();
    }
}
