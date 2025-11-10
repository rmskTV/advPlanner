<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api'])->prefix('vk-ads')->name('vk-ads.')->group(function () {});
