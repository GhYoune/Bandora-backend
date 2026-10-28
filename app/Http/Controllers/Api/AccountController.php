<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    /** International format, e.g. +213550123456 (spaces are removed before checking). */
    public const PHONE_RULE = 'regex:/^\+?\d{9,15}$/';

    public function __construct(private Api $present) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->present->me($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        if ($request->has('whatsapp')) {
            $request->merge(['whatsapp' => preg_replace('/\s+/', '', (string) $request->input('whatsapp')) ?: null]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'whatsapp' => ['sometimes', 'nullable', 'string', self::PHONE_RULE],
        ], ['whatsapp.regex' => 'Enter a valid phone number, for example +213 550 12 34 56.']);

        $request->user()->update($data);

        return response()->json($this->present->me($request->user()->fresh()));
    }

    public function password(Request $request): Response
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'current_password:web'],
            'password' => ['required', 'string', Password::min(8)],
            'passwordConfirmation' => ['required', 'same:password'],
        ], [
            'currentPassword.current_password' => 'Your current password is incorrect.',
            'passwordConfirmation.same' => 'The passwords do not match.',
        ]);
        $request->user()->update(['password' => $data['password']]);

        return response()->noContent();
    }
}
