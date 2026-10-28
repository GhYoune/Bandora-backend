<?php

namespace Tests;

use App\Enums\Skill;
use App\Models\Test;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Requests from the Next.js app: Sanctum treats them as stateful (cookie session). */
    protected array $spa = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    /** Packs, admin, demo student (3-month pack) and the sample tests. */
    protected function seedCatalog(): void
    {
        $this->seed();
    }

    protected function student(array $attributes = []): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(['credits' => User::FREE_CREDITS, ...$attributes])->save();
        $this->actAs($user);

        return $user;
    }

    /** Logs in as $user (fresh from the database), replacing whoever was logged in. */
    protected function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $user = $user->fresh();
        Sanctum::actingAs($user);
        $this->actingAs($user, 'web');
    }

    protected function test(string $title): Test
    {
        return Test::where('title', $title)->firstOrFail();
    }

    protected function firstOf(Skill $skill, bool $free): Test
    {
        return Test::where('skill', $skill)->where('is_free', $free)->orderBy('sort')->firstOrFail();
    }
}
