<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});

Route::get('/{pathMatch}', function () {
    return view('app');
});

Route::get('/uikit/{pathMatch}', function () {
    return view('app');
});
