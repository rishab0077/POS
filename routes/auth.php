<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\SecurityController;

Route::middleware(['private.access', 'guest'])->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.update');
});

Route::middleware(['private.access', 'auth'])->group(function () {
    Route::get('mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('mfa/setup', [MfaController::class, 'confirmSetup'])->middleware('throttle:6,1')->name('mfa.setup.confirm');
    Route::get('mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('mfa/challenge', [MfaController::class, 'verifyChallenge'])->name('mfa.challenge.verify');

    Route::get('security', [SecurityController::class, 'index'])->name('security.index');
    Route::post('security/recovery-codes', [SecurityController::class, 'regenerateRecoveryCodes'])->name('security.recovery-codes');
    Route::delete('security/mfa', [SecurityController::class, 'disableMfa'])->name('security.mfa.disable');
    Route::delete('security/sessions/others', [SecurityController::class, 'destroyOtherSessions'])->name('security.sessions.others.destroy');
    Route::delete('security/sessions/{sessionId}', [SecurityController::class, 'destroySession'])->name('security.sessions.destroy');

    Route::get('verify-email', [EmailVerificationPromptController::class, '__invoke'])
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', [VerifyEmailController::class, '__invoke'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
