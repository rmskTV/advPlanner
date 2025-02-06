<?php

use Illuminate\Support\Facades\Route;
use Modules\AdvBlocks\app\Http\Controllers\AdvBlockTypesController;

/*
 *--------------------------------------------------------------------------
 * API Routes
 *--------------------------------------------------------------------------
 *
 * Here is where you can register API routes for your application. These
 * routes are loaded by the RouteServiceProvider within a group which
 * is assigned the "api" middleware group. Enjoy building your API!
 *
*/


/** AdvBlockTypes */
Route::group([
    'middleware' => ['api'],
    'prefix' => 'advBlockTypes',
], function () {
    Route::get('/{id}', [AdvBlockTypesController::class, 'show']);
    Route::get('/', [AdvBlockTypesController::class, 'index']);
    Route::put('/', [AdvBlockTypesController::class, 'create']);
    Route::patch('/{id}', [AdvBlockTypesController::class, 'update']);
    Route::delete('/{id}', [AdvBlockTypesController::class, 'destroy']);
});
