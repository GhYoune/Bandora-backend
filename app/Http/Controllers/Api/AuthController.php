<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Api;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** Sanctum SPA auth: cookie sessions on the web guard. */
class AuthController extends Controller
{
    public function __construct(private Api $present) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', PasswordRule::min(8)],
            'passwordConfirmation' => ['required', 'same:password'],
        ], [
            'email.unique' => 'An account with this email already exists.',
            'passwordConfirmation.same' => 'The passwords do not match.',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
        ]);
        $user->forceFill(['credits' => User::FREE_CREDITS])->save();

        event(new Registered($user)); // sends the verification email
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return response()->json($this->present->me($user), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        if (! Auth::guard('web')->attempt(['email' => Str::lower($data['email']), 'password' => $data['password']], $data['remember'] ?? false)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        $request->session()->regenerate();

        return response()->json($this->present->me($request->user()));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        // Same answer whether or not the account exists, so emails cannot be probed.
        Password::sendResetLink(['email' => Str::lower($request->input('email'))]);

        return response()->json(['message' => 'If an account exists for this email, we have sent a password reset link.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', PasswordRule::min(8)],
            'passwordConfirmation' => ['required', 'same:password'],
        ], ['passwordConfirmation.same' => 'The passwords do not match.']);

        $status = Password::reset(
            ['email' => Str::lower($data['email']), 'password' => $data['password'], 'token' => $data['token']],
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired. Request a new one.']);
        }

        return response()->json(['message' => 'Your password has been changed.']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'Verification link sent.'], 202);
    }
}
