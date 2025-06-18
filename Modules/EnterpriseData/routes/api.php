<?php

use Illuminate\Support\Facades\Route;
use Modules\EnterpriseData\app\Http\Controllers\ExchangeFtpConnectorController as Controller;

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

/** ExchangeFtpConnectors*/
Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'exchangeConnectors',
], function () {
    //    Route::get('/{id}', [Controller::class, 'show']);
    Route::get('/', [Controller::class, 'index']);
    Route::post('/', [Controller::class, 'store']);
    //    Route::patch('/{id}', [Controller::class, 'update']);
    //    Route::delete('/{id}', [Controller::class, 'destroy']);
});
