<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Browser redirects (not fetch): Google sign-in and the email verification link.
 * They live on the web middleware group so they get a session, then send the student back to the app.
 */
class OAuthController extends Controller
{
    public function googleRedirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()->away(config('bandora.frontend_url').'/login?error=google');
        }

        $user = User::where('google_id', $google->getId())->first()
            ?? User::where('email', Str::lower($google->getEmail()))->first();

        if (! $user) {
            $user = User::create([
                'name' => $google->getName() ?: Str::before($google->getEmail(), '@'),
                'email' => Str::lower($google->getEmail()),
                'google_id' => $google->getId(),
            ]);
            $user->forceFill(['credits' => User::FREE_CREDITS])->save();
        }
        // Google has already verified the address.
        $user->forceFill(['google_id' => $google->getId(), 'email_verified_at' => $user->email_verified_at ?? now()])->save();

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->away(config('bandora.frontend_url').'/app');
    }

    public function verifyEmail(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->away(config('bandora.frontend_url').'/app?verified=1');
    }
}
