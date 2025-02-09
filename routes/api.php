<?php

use App\Http\Controllers\AccountingUnitsController;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => ['api'],
    'prefix' => 'accountingUnits',
], function () {
    Route::get('/{id}', [AccountingUnitsController::class, 'show']);
    Route::get('/', [AccountingUnitsController::class, 'index']);
});
