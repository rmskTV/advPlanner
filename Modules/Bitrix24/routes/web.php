<?php

use Illuminate\Support\Facades\Route;
use Modules\Bitrix24\Http\Controllers\Bitrix24Controller;

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
    Route::resource('bitrix24', Bitrix24Controller::class)->names('bitrix24');
});
