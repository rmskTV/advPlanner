<?php

use Illuminate\Support\Facades\Route;
use Modules\BroadcastingDayTemplates\app\Http\Controllers\BroadcastingDayTemplatesController;
use Modules\BroadcastingDayTemplates\app\Http\Controllers\BroadcastingDayTemplateSlotsController;

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

/** BroadcastingDayTemplates */
Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'broadcastingDayTemplates',
], function () {
    Route::get('/{id}', [BroadcastingDayTemplatesController::class, 'show']);
    Route::get('/', [BroadcastingDayTemplatesController::class, 'index']);
    Route::put('/', [BroadcastingDayTemplatesController::class, 'create']);
    Route::patch('/{id}', [BroadcastingDayTemplatesController::class, 'update']);
    Route::delete('/{id}', [BroadcastingDayTemplatesController::class, 'destroy']);
});

/** BroadcastingDayTemplateSlots */
Route::group([
    'middleware' => ['api'],
    'prefix' => 'broadcastingDayTemplateSlots',
], function () {
    Route::get('/', [BroadcastingDayTemplateSlotsController::class, 'index']);
    Route::put('/', [BroadcastingDayTemplateSlotsController::class, 'create']);
    Route::patch('/{id}', [BroadcastingDayTemplateSlotsController::class, 'update']);
    Route::delete('/{id}', [BroadcastingDayTemplateSlotsController::class, 'destroy']);
});
