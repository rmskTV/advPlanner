<?php

use Illuminate\Support\Facades\Route;
use Modules\AdvBlocks\app\Http\Controllers\AdvBlockBroadcastingController;
use Modules\AdvBlocks\app\Http\Controllers\AdvBlocksController;
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

/** AdvBlocks */
Route::group([
    'middleware' => ['api'],
    'prefix' => 'advBlocks',
], function () {
    Route::get('/{id}', [AdvBlocksController::class, 'show']);
    Route::get('/', [AdvBlocksController::class, 'index']);
    Route::put('/', [AdvBlocksController::class, 'create']);
    Route::patch('/{id}', [AdvBlocksController::class, 'update']);
    Route::delete('/{id}', [AdvBlocksController::class, 'destroy']);
});

/** AdvBlocksBroadcasting */
Route::group([
    'middleware' => ['api'],
    'prefix' => 'advBlocksBroadcasting',
], function () {
    Route::get('/{id}', [AdvBlockBroadcastingController::class, 'show']);
    Route::get('/', [AdvBlockBroadcastingController::class, 'index']);
    Route::put('/', [AdvBlockBroadcastingController::class, 'create']);
    Route::patch('/{id}', [AdvBlockBroadcastingController::class, 'update']);
    Route::delete('/{id}', [AdvBlockBroadcastingController::class, 'destroy']);
});
