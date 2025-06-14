<?php

use App\Http\Controllers\AccountingUnitsController;
use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RefreshTokenController;
use App\Http\Controllers\VideoFilesController;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => 'api',
    'prefix' => 'auth',
], function () {

    Route::post('/login', LoginController::class)->name('login');
    Route::post('/logout', LogoutController::class)->middleware('auth:api')->name('logout');
    Route::post('/refresh-token', RefreshTokenController::class)->middleware('auth:api')->name('refreshToken');
    Route::get('/me', CurrentUserController::class)->middleware('auth:api')->name('me');
});

Route::group([
    'middleware' => ['api'],
    'prefix' => 'accountingUnits',
], function () {
    Route::get('/{id}', [AccountingUnitsController::class, 'show']);
    Route::get('/', [AccountingUnitsController::class, 'index']);
});

Route::group([
    'middleware' => ['api', 'auth:api'],
    'prefix' => 'video',
], function () {
    Route::post('/', [VideoFilesController::class, 'upload']);
    Route::get('/{id}', [VideoFilesController::class, 'getVideoInfo']);
});
