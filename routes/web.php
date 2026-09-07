<?php

use App\Http\Controllers\AuditExportController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\ResultDownloadController;
use App\Http\Controllers\TeamSwitchController;
use App\Http\Controllers\Webhooks\SlackInteractionController;
use App\Http\Controllers\Webhooks\TeamsActionController;
use App\Livewire\Admin\TeamMembers;
use App\Livewire\Admin\Teams;
use App\Livewire\Admin\Users;
use App\Livewire\Approvals\Index as ApprovalsIndex;
use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Connections\Index as ConnectionsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Masking\Index as MaskingIndex;
use App\Livewire\Profile;
use App\Livewire\Requests\Index as RequestsIndex;
use App\Livewire\Requests\Show as RequestsShow;
use App\Livewire\Settings\ChatOps as ChatOpsSettings;
use App\Livewire\Studio\QueryStudio;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::prefix('webhooks')->middleware('throttle:60,1')->group(function () {
    Route::post('/slack/interactions', SlackInteractionController::class)
        ->middleware('slack.signature')
        ->name('webhooks.slack');

    Route::post('/teams/actions', TeamsActionController::class)
        ->middleware('teams.hmac')
        ->name('webhooks.teams');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('/teams/{team}/switch', TeamSwitchController::class)->name('teams.switch');

    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/profile', Profile::class)->name('profile');

    Route::middleware('role:dba')->group(function () {
        Route::get('/connections', ConnectionsIndex::class)->name('connections.index');
        Route::get('/approvals', ApprovalsIndex::class)->name('approvals.index');
        Route::get('/masking', MaskingIndex::class)->name('masking.index');
        Route::get('/settings/chatops', ChatOpsSettings::class)->name('settings.chatops');
    });

    Route::middleware('role:auditor')->group(function () {
        Route::get('/audit', AuditIndex::class)->name('audit.index');
        Route::get('/audit/export', AuditExportController::class)->name('audit.export');
    });

    Route::middleware('role:dba,developer')->group(function () {
        Route::get('/studio', QueryStudio::class)->name('studio');
    });

    Route::middleware('role:dba,developer,auditor')->group(function () {
        Route::get('/requests', RequestsIndex::class)->name('requests.index');
        Route::get('/requests/{queryRequest}', RequestsShow::class)->name('requests.show');
        Route::get('/requests/{queryRequest}/download', ResultDownloadController::class)->name('requests.download');
    });

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/teams', Teams::class)->name('teams');
        Route::get('/teams/{team}/members', TeamMembers::class)->name('teams.members');
        Route::get('/users', Users::class)->name('users');
    });
});
