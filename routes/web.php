<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\TeamSwitchController;
use App\Livewire\Admin\TeamMembers;
use App\Livewire\Admin\Teams;
use App\Livewire\Admin\Users;
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

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/teams', Teams::class)->name('teams');
        Route::get('/teams/{team}/members', TeamMembers::class)->name('teams.members');
        Route::get('/users', Users::class)->name('users');
    });
});
