<?php

use App\Http\Controllers\Scim\GroupsController;
use App\Http\Controllers\Scim\ServiceProviderConfigController;
use App\Http\Controllers\Scim\UsersController;
use Illuminate\Support\Facades\Route;

/*
| SCIM 2.0 (RFC 7643/7644), ADR-026. bootstrap/app.php registers this file under
| /scim/v2 behind AuthenticateScimToken and outside the web group: a SCIM client
| has no session or CSRF token. Ids are the numeric ids of the rows.
*/

Route::get('ServiceProviderConfig', [ServiceProviderConfigController::class, 'serviceProviderConfig'])->name('service-provider-config');
Route::get('Schemas', [ServiceProviderConfigController::class, 'schemas'])->name('schemas.index');
Route::get('Schemas/{id}', [ServiceProviderConfigController::class, 'schema'])->where('id', '[A-Za-z0-9:._-]+')->name('schemas.show');
Route::get('ResourceTypes', [ServiceProviderConfigController::class, 'resourceTypes'])->name('resource-types.index');
Route::get('ResourceTypes/{id}', [ServiceProviderConfigController::class, 'resourceType'])->where('id', '[A-Za-z]+')->name('resource-types.show');

Route::get('Users', [UsersController::class, 'index'])->name('users.index');
Route::post('Users', [UsersController::class, 'store'])->name('users.store');
Route::get('Users/{id}', [UsersController::class, 'show'])->name('users.show');
Route::put('Users/{id}', [UsersController::class, 'replace'])->name('users.replace');
Route::patch('Users/{id}', [UsersController::class, 'update'])->name('users.update');
Route::delete('Users/{id}', [UsersController::class, 'destroy'])->name('users.destroy');

Route::get('Groups', [GroupsController::class, 'index'])->name('groups.index');
Route::post('Groups', [GroupsController::class, 'store'])->name('groups.store');
Route::get('Groups/{id}', [GroupsController::class, 'show'])->name('groups.show');
Route::put('Groups/{id}', [GroupsController::class, 'replace'])->name('groups.replace');
Route::patch('Groups/{id}', [GroupsController::class, 'update'])->name('groups.update');
Route::delete('Groups/{id}', [GroupsController::class, 'destroy'])->name('groups.destroy');
