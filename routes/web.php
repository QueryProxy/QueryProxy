<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\TeamSwitchController;
use App\Livewire\Admin\TeamMembers;
use App\Livewire\Admin\Teams;
use App\Livewire\Admin\Users;
use App\Livewire\Approvals\Index as ApprovalsIndex;
use App\Livewire\Connections\Index as ConnectionsIndex;
use App\Livewire\Masking\Index as MaskingIndex;
use App\Livewire\Requests\Index as RequestsIndex;
use App\Livewire\Requests\Show as RequestsShow;
use App\Livewire\Studio\QueryStudio;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('/teams/{team}/switch', TeamSwitchController::class)->name('teams.switch');

    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::middleware('role:dba')->group(function () {
        Route::get('/connections', ConnectionsIndex::class)->name('connections.index');
        Route::get('/approvals', ApprovalsIndex::class)->name('approvals.index');
        Route::get('/masking', MaskingIndex::class)->name('masking.index');
    });

    Route::middleware('role:dba,developer')->group(function () {
        Route::get('/studio', QueryStudio::class)->name('studio');
    });

    Route::middleware('role:dba,developer,auditor')->group(function () {
        Route::get('/requests', RequestsIndex::class)->name('requests.index');
        Route::get('/requests/{queryRequest}', RequestsShow::class)->name('requests.show');
    });

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/teams', Teams::class)->name('teams');
        Route::get('/teams/{team}/members', TeamMembers::class)->name('teams.members');
        Route::get('/users', Users::class)->name('users');
    });
});
