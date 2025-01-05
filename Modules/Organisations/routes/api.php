<?php

use Illuminate\Support\Facades\Route;
use Modules\Organisations\app\Http\Controllers\OrganisationsController as Controller;

/** Organisations */
Route::group([
    'middleware' => ['api'],
    'prefix' => 'organisations',
], function () {
    Route::get('/{id}', [Controller::class, 'show']);
    Route::get('/', [Controller::class, 'index']);
    Route::put('/', [Controller::class, 'create']);
    Route::patch('/{id}', [Controller::class, 'update']);
    Route::delete('/{id}', [Controller::class, 'destroy']);
});
