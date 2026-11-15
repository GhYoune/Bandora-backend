<?php

namespace Tests;

use App\Enums\Skill;
use App\Models\Pack;
use App\Models\Test;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * RefreshDatabase wipes the database. If the config is cached (php artisan optimize / config:cache),
     * phpunit.xml's in-memory SQLite is ignored and the dev database would be wiped instead: refuse.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = config('database.default');
        if ($connection !== 'sqlite' || config("database.connections.{$connection}.database") !== ':memory:') {
            fwrite(STDERR, "\nTests must use the in-memory SQLite database, not '{$connection}'. Run: php artisan optimize:clear\n");
            exit(1);
        }
    }

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

    /** A student with a running pack (AI marking needs one). $attributes (e.g. credits) apply after the pack. */
    protected function paidStudent(array $attributes = []): User
    {
        $user = $this->student();
        app(PaymentService::class)->activate($user, Pack::orderBy('sort')->first() ?? Pack::create([
            'name' => 'Bronze', 'duration_days' => 30, 'price_dzd' => 3500, 'credits' => 10, 'sort' => 1,
        ]));
        if ($attributes) {
            $user->forceFill($attributes)->save();
        }
        $this->actAs($user);

        return $user->fresh();
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
