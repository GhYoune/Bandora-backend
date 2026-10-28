<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AttemptController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TestController;
use Illuminate\Support\Facades\Route;

/*
 * Implements contract/openapi.yaml. Auth is Sanctum SPA cookie sessions (statefulApi in bootstrap/app.php).
 * Browser redirects (Google, email verification, recording playback) are in routes/web.php.
 */
Route::prefix('v1')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::get('packs', [PaymentController::class, 'packs']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:6,1');

        Route::get('me', [AccountController::class, 'show']);
        Route::patch('me', [AccountController::class, 'update']);
        Route::put('me/password', [AccountController::class, 'password']);

        Route::get('payment-requests', [PaymentController::class, 'index']);
        Route::post('payment-requests', [PaymentController::class, 'store']);
        Route::delete('payment-requests/{payment}', [PaymentController::class, 'destroy']);

        Route::get('tests', [TestController::class, 'index']);
        Route::get('tests/{test}', [TestController::class, 'show']);
        Route::post('tests/{test}/attempts', [TestController::class, 'start']);

        Route::get('attempts', [AttemptController::class, 'index']);
        Route::get('attempts/{attempt}', [AttemptController::class, 'show']);
        Route::patch('attempts/{attempt}', [AttemptController::class, 'save']);
        Route::post('attempts/{attempt}/recordings', [AttemptController::class, 'uploadRecording']);
        Route::post('attempts/{attempt}/submit', [AttemptController::class, 'submit']);
        Route::get('attempts/{attempt}/result', [AttemptController::class, 'result']);

        Route::get('dashboard', [AttemptController::class, 'dashboard']);
    });
});
