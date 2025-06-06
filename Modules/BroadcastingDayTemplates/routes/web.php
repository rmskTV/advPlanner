<?php

use Illuminate\Support\Facades\Route;
use Modules\BroadcastingDayTemplates\app\Http\Controllers\BroadcastingDayTemplatesController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::group([], function () {
    Route::resource('broadcastingdaytemplates', BroadcastingDayTemplatesController::class)->names('broadcastingdaytemplates');
});
