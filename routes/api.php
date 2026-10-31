<?php

use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\RequestController;
use App\Http\Controllers\Api\V1\RequestResultController;
use App\Http\Middleware\ThrottleFailedApiAuthentication;
use Illuminate\Support\Facades\Route;

/*
| REST API (ADR-024), served under /api/v1 and described by docs/openapi.yaml.
| Every route needs a bearer token (Sanctum personal access token) and one of
| its scopes; what the owner may do is still decided by the Policies, the
| scope only narrows it. tests/Feature/Api/OpenApiSpecTest.php keeps this file
| and the specification in step.
*/

Route::prefix('v1')->name('api.v1.')->middleware([ThrottleFailedApiAuthentication::class, 'auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('connections', [ConnectionController::class, 'index'])
        ->middleware('ability:requests:read,requests:submit')
        ->name('connections.index');

    Route::post('requests', [RequestController::class, 'store'])
        ->middleware('abilities:requests:submit')
        ->name('requests.store');

    Route::get('requests/{queryRequest}', [RequestController::class, 'show'])
        ->middleware('abilities:requests:read')
        ->name('requests.show');

    Route::get('requests/{queryRequest}/result', RequestResultController::class)
        ->middleware('abilities:results:read')
        ->name('requests.result');

    Route::post('requests/{queryRequest}/approve', [ApprovalController::class, 'approve'])
        ->middleware('abilities:approvals:write')
        ->name('requests.approve');

    Route::post('requests/{queryRequest}/reject', [ApprovalController::class, 'reject'])
        ->middleware('abilities:approvals:write')
        ->name('requests.reject');

    // Withdrawing a request is the requester's own act, so it needs the scope that files one.
    Route::post('requests/{queryRequest}/cancel', [ApprovalController::class, 'cancel'])
        ->middleware('abilities:requests:submit')
        ->name('requests.cancel');
});
