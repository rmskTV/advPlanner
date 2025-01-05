<?php

use Illuminate\Support\Facades\Route;
use Modules\MediaProducts\app\Http\Controllers\MediaProductsController as Controller;

/** MediaProducts */
Route::group([
    'middleware' => ['api'],
    'prefix' => 'mediaProducts',
], function () {
    Route::get('/{id}', [Controller::class, 'show']);
    Route::get('/', [Controller::class, 'index']);
    Route::put('/', [Controller::class, 'create']);
    Route::patch('/{id}', [Controller::class, 'update']);
    Route::delete('/{id}', [Controller::class, 'destroy']);
});
