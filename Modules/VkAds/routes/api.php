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


});

