<?php

use App\Http\Controllers\AccountingUnitsController;
use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RefreshTokenController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;


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
