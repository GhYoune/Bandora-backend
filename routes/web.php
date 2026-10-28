<?php

use App\Http\Controllers\Api\AttemptController;
use App\Http\Controllers\Api\OAuthController;
use Illuminate\Support\Facades\Route;

// The student app is the Next.js frontend; this backend only serves the API and the Filament admin.
Route::redirect('/', '/admin');

// Browser redirects that need a session (not fetch calls), kept under the API prefix.
Route::get('api/v1/auth/google/redirect', [OAuthController::class, 'googleRedirect']);
Route::get('api/v1/auth/google/callback', [OAuthController::class, 'googleCallback']);
Route::get('api/v1/auth/email/verify/{id}/{hash}', [OAuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
Route::get('api/v1/recordings/{recording}/audio', [AttemptController::class, 'audio'])
    ->middleware('signed')
    ->name('recordings.audio');
