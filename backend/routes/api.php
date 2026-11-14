<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
| All routes here are served under /api/v1 (see bootstrap/app.php).
*/

Route::get('/', fn () => [
    'name' => config('app.name'),
    'api_version' => 'v1',
])->name('api.root');

Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', RegisterController::class)->name('auth.register');
        Route::post('login', [SessionController::class, 'store'])->name('auth.login');
        Route::post('forgot-password', [PasswordController::class, 'forgot'])->name('auth.password.forgot');
        Route::post('reset-password', [PasswordController::class, 'reset'])->name('auth.password.reset');
    });

    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->whereNumber('id')->middleware('throttle:auth')->name('auth.verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [SessionController::class, 'destroy'])->name('auth.logout');
        Route::post('email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:auth')->name('auth.verification.resend');
    });
});

Route::get('invitations/{token}', [InvitationController::class, 'preview'])
    ->middleware('throttle:auth')->name('invitations.preview');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [MeController::class, 'show'])->name('me.show');
    Route::patch('me', [MeController::class, 'update'])->name('me.update');

    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    // Everything below acts inside one workspace; non-members get 404.
    Route::prefix('workspaces/{workspace}')->middleware('workspace')->group(function () {
        Route::get('/', [WorkspaceController::class, 'show'])->name('workspaces.show');
        Route::patch('/', [WorkspaceController::class, 'update'])->name('workspaces.update');
        Route::delete('/', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');

        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::patch('members/{user}', [MemberController::class, 'update'])->whereNumber('user')->name('members.update');
        Route::delete('members/{user}', [MemberController::class, 'destroy'])->whereNumber('user')->name('members.destroy');

        Route::get('invitations', [InvitationController::class, 'index'])->name('workspace-invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('workspace-invitations.store');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])
            ->whereNumber('invitation')->name('workspace-invitations.destroy');

        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    });
});
