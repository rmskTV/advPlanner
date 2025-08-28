<?php

namespace Modules\VkAds\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\VkAds\app\Services\VkAdsWebhookService;

class VkAdsWebhookController extends Controller
{
    public function __construct(
        private VkAdsWebhookService $webhookService
    ) {}

    /**
     * Обработка изменения статуса кампании
     */
    public function campaignStatus(Request $request): JsonResponse
    {
        if (! $this->webhookService->validateSignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $this->webhookService->handleCampaignStatusChange($request->all());

        return response()->json(['status' => 'ok']);
    }

    /**
     * Обработка результатов модерации
     */
    public function moderationResult(Request $request): JsonResponse
    {
        if (! $this->webhookService->validateSignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $this->webhookService->handleModerationResult($request->all());

        return response()->json(['status' => 'ok']);
    }
}
