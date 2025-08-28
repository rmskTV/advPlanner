<?php

use Illuminate\Support\Facades\Route;
use Modules\VkAds\app\Http\Controllers\AgencyController;
use Modules\VkAds\app\Http\Controllers\VkAdsAccountController;
use Modules\VkAds\app\Http\Controllers\VkAdsAdController;
use Modules\VkAds\app\Http\Controllers\VkAdsAdGroupController;
use Modules\VkAds\app\Http\Controllers\VkAdsCampaignController;
use Modules\VkAds\app\Http\Controllers\VkAdsCreativeController;
use Modules\VkAds\app\Http\Controllers\VkAdsStatisticsController;
use Modules\VkAds\app\Http\Controllers\VkAdsWebhookController;

Route::middleware(['auth:api'])->prefix('vk-ads')->name('vk-ads.')->group(function () {

    // === АККАУНТЫ ===
    Route::apiResource('accounts', VkAdsAccountController::class);
    Route::post('accounts/{id}/sync', [VkAdsAccountController::class, 'sync'])->name('accounts.sync');
    Route::post('accounts/sync-all', [VkAdsAccountController::class, 'syncAll'])->name('accounts.sync-all');
    Route::get('accounts/{id}/balance', [VkAdsAccountController::class, 'getBalance'])->name('accounts.balance');

    // === КАМПАНИИ ===
    Route::apiResource('accounts.campaigns', VkAdsCampaignController::class)->parameters([
        'accounts' => 'accountId',
        'campaigns' => 'campaignId',
    ]);
    Route::post('accounts/{accountId}/campaigns/{campaignId}/pause', [VkAdsCampaignController::class, 'pause'])->name('campaigns.pause');
    Route::post('accounts/{accountId}/campaigns/{campaignId}/resume', [VkAdsCampaignController::class, 'resume'])->name('campaigns.resume');
    Route::post('accounts/{accountId}/campaigns/{campaignId}/copy', [VkAdsCampaignController::class, 'copy'])->name('campaigns.copy');

    // === ГРУППЫ ОБЪЯВЛЕНИЙ ===
    Route::apiResource('campaigns.ad-groups', VkAdsAdGroupController::class)->parameters([
        'campaigns' => 'campaignId',
        'ad-groups' => 'adGroupId',
    ]);

    // === КРЕАТИВЫ ===
    Route::prefix('accounts/{accountId}')->name('accounts.')->group(function () {
        Route::apiResource('creatives', VkAdsCreativeController::class)->only(['index', 'show', 'update', 'destroy']);

        // Создание креативов разных типов
        Route::post('creatives/video', [VkAdsCreativeController::class, 'createVideoCreative'])->name('creatives.video.store');
        Route::post('creatives/image', [VkAdsCreativeController::class, 'createImageCreative'])->name('creatives.image.store');

        // Специализированные методы
        Route::get('creatives/instream', [VkAdsCreativeController::class, 'getInstreamCreatives'])->name('creatives.instream');
        Route::post('creatives/{creativeId}/sync', [VkAdsCreativeController::class, 'sync'])->name('creatives.sync');
    });

    // Валидация креативов
    Route::post('creatives/validate-instream', [VkAdsCreativeController::class, 'validateInstreamVideos'])->name('creatives.validate.instream');

    // === ОБЪЯВЛЕНИЯ ===
    Route::prefix('ad-groups/{adGroupId}')->name('ad-groups.')->group(function () {
        Route::apiResource('ads', VkAdsAdController::class)->only(['index', 'show', 'update', 'destroy']);

        // Создание объявлений разных типов
        Route::post('ads/instream', [VkAdsAdController::class, 'createInstream'])->name('ads.instream.store');
        Route::post('ads/universal', [VkAdsAdController::class, 'createUniversal'])->name('ads.universal.store');
        Route::post('ads/ab-test', [VkAdsAdController::class, 'createAbTest'])->name('ads.ab-test.store');

        // Управление статусом
        Route::post('ads/{adId}/status', [VkAdsAdController::class, 'updateStatus'])->name('ads.status');

        // Управление креативами в объявлении
        Route::post('ads/{adId}/creatives', [VkAdsAdController::class, 'addCreativeVariant'])->name('ads.creatives.add');
        Route::delete('ads/{adId}/creatives', [VkAdsAdController::class, 'removeCreativeVariant'])->name('ads.creatives.remove');

        // Специализированные получения
        Route::get('ads/instream', [VkAdsAdController::class, 'getInstreamAds'])->name('ads.instream');
    });

    // === СТАТИСТИКА ===
    Route::prefix('statistics')->name('statistics.')->group(function () {
        Route::get('campaigns/{campaignId}', [VkAdsStatisticsController::class, 'getCampaignStats'])->name('campaign');
        Route::get('ad-groups/{adGroupId}', [VkAdsStatisticsController::class, 'getAdGroupStats'])->name('ad-group');
        Route::get('ads/{adId}', [VkAdsStatisticsController::class, 'getAdStats'])->name('ad');
        Route::get('accounts/{accountId}', [VkAdsStatisticsController::class, 'getAccountStats'])->name('account');
        Route::post('export', [VkAdsStatisticsController::class, 'exportStats'])->name('export');
    });

    // === АГЕНТСКИЕ ФУНКЦИИ ===
    Route::prefix('agency')->name('agency.')->group(function () {
        Route::get('clients', [AgencyController::class, 'getClients'])->name('clients.index');
        Route::post('clients', [AgencyController::class, 'createClient'])->name('clients.store');
        Route::post('create-ad-groups-from-order', [AgencyController::class, 'createAdGroupsFromOrder'])->name('create-ad-groups-from-order');
        Route::post('generate-act', [AgencyController::class, 'generateAct'])->name('generate-act');
        Route::get('dashboard', [AgencyController::class, 'getDashboard'])->name('dashboard');
    });
});

// === ПУБЛИЧНЫЕ МАРШРУТЫ (вебхуки) ===
Route::prefix('vk-ads/webhooks')->name('vk-ads.webhooks.')->group(function () {
    Route::post('campaign-status', [VkAdsWebhookController::class, 'campaignStatus'])->name('campaign-status');
    Route::post('moderation-result', [VkAdsWebhookController::class, 'moderationResult'])->name('moderation-result');
});
