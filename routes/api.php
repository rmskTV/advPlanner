<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccountingUnitsController;


Route::group([
    'middleware' => ['api'],
    'prefix' => 'accountingUnits',
], function () {
    Route::get('/{id}', [AccountingUnitsController::class, 'show']);
    Route::get('/', [AccountingUnitsController::class, 'index']);
});
