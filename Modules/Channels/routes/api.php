<?php

use Illuminate\Support\Facades\Route;
use Modules\Channels\app\Http\Controllers\ChannelsController as Controller;

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

/** Organisations */
Route::group([
    'middleware' => ['api'],
    'prefix' => 'channels',
], function () {
    Route::get('/{id}', [Controller::class, 'show']);
    Route::get('/', [Controller::class, 'index']);
    Route::put('/', [Controller::class, 'create']);
    Route::patch('/{id}', [Controller::class, 'update']);
    Route::delete('/{id}', [Controller::class, 'destroy']);
});
