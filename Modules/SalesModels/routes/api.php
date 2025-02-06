<?php

use Illuminate\Support\Facades\Route;
use Modules\SalesModels\app\Http\Controllers\SalesModelsController as Controller;

Route::group([
    'middleware' => ['api'],
    'prefix' => 'salesModels',
], function () {
    Route::get('/{id}', [Controller::class, 'show']);
    Route::get('/', [Controller::class, 'index']);
    Route::put('/', [Controller::class, 'create']);
    Route::patch('/{id}', [Controller::class, 'update']);
    Route::delete('/{id}', [Controller::class, 'destroy']);
});
