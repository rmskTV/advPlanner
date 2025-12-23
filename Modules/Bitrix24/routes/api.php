<?php

use Illuminate\Support\Facades\Route;
use Modules\Bitrix24\app\Http\Controllers\Bitrix24Controller;

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

Route::group([
    'middleware' => ['api'],
    'prefix' => 'bitrix24',
], function () {
    Route::get('dealsw', [Bitrix24Controller::class, 'deals']);
    Route::post('webhook', [Bitrix24Controller::class, 'handleWebhook']);
});
