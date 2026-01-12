<?php

use App\Http\Controllers\BitrixAppController;
use Illuminate\Support\Facades\Route;
use Modules\Bitrix24\app\Services\Bitrix24Service;

Route::get('/', function () {
    return view('app');
});

Route::get('/{pathMatch}', function () {
    return view('app');
});

Route::get('/uikit/{pathMatch}', function () {
    return view('app');
});

Route::prefix('/bitrix')
    ->withoutMiddleware(['web'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class])
    ->middleware(['api', \App\Http\Middleware\BitrixFrameHeaders::class])->group(function () {
    Route::any('/install', [BitrixAppController::class, 'install']);
    Route::any('/handler', [BitrixAppController::class, 'handler']);
    Route::any('/requisite-tab', [BitrixAppController::class, 'requisiteTab']);
    //Route::any('/requisite-selector', [BitrixAppController::class, 'requisiteSelector']);
    //Route::post('/save-requisite', [BitrixAppController::class, 'saveRequisite']);
    //Route::get('/get-requisites/{companyId}', [BitrixAppController::class, 'getRequisites']);
        Route::get('/fix-placement', function () {
            $auth = \Illuminate\Support\Facades\Cache::get('bitrix_auth');
            if (!$auth) return 'No auth - переустановите приложение';

            $domain = $auth['domain'];
            $token = $auth['access_token'];

            // 1. Удаляем старый (с http)
            $unbind = \Illuminate\Support\Facades\Http::post("https://{$domain}/rest/placement.unbind", [
                'auth' => $token,
                'PLACEMENT' => 'CRM_DYNAMIC_1064_DETAIL_TAB',
                'HANDLER' => 'http://new.bst.bratsk.ru/bitrix/requisite-tab',
            ])->json();

            // 2. Регистрируем с HTTPS
            $bind = \Illuminate\Support\Facades\Http::post("https://{$domain}/rest/placement.bind", [
                'auth' => $token,
                'PLACEMENT' => 'CRM_DYNAMIC_1064_DETAIL_TAB',
                'HANDLER' => 'https://new.bst.bratsk.ru/bitrix/requisite-tab',  // ← HTTPS!
                'TITLE' => 'Привязка к реквизиту',
            ])->json();

            return response()->json([
                'unbind_http' => $unbind,
                'bind_https' => $bind
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        });

});
