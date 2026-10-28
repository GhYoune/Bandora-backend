<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Marking\AiMarker;
use App\Services\Marking\FakeAiMarker;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiMarker::class, fn () => match (config('bandora.marking.driver')) {
            'fake' => new FakeAiMarker,
            default => throw new InvalidArgumentException('Unknown MARKING_DRIVER: '.config('bandora.marking.driver')),
        });
    }

    public function boot(): void
    {
        // The reset page is in the Next.js app.
        ResetPassword::createUrlUsing(fn (User $user, string $token) => config('bandora.frontend_url')
            .'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
