<?php

namespace Modules\VkAds\app\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\VkAds\app\Events\CampaignStatusChanged;
use Modules\VkAds\app\Events\ModerationCompleted;
use Modules\VkAds\app\Models\VkAdsAd;
use Modules\VkAds\app\Models\VkAdsCampaign;
use Modules\VkAds\app\Models\VkAdsCreative;

class VkAdsWebhookService
{
    /**
     * Валидация подписи вебхука
     */
    public function validateSignature(Request $request): bool
    {
        $signature = $request->header('X-VK-Signature');
        $body = $request->getContent();
        $secret = config('vkads.webhooks.secret');

        if (! $signature || ! $secret) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $body, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Обработка изменения статуса кампании
     */
    public function handleCampaignStatusChange(array $data): void
    {
        try {
            $vkCampaignId = $data['campaign_id'] ?? null;
            $newStatus = $data['status'] ?? null;

            if (! $vkCampaignId || ! $newStatus) {
                Log::warning('Invalid campaign status webhook data', $data);

                return;
            }

            $campaign = VkAdsCampaign::where('vk_campaign_id', $vkCampaignId)->first();

            if (! $campaign) {
                Log::warning("Campaign not found for VK ID: {$vkCampaignId}");

                return;
            }

            $oldStatus = $campaign->status;
            $campaign->update([
                'status' => $this->mapVkStatusToLocal($newStatus),
                'last_sync_at' => now(),
            ]);

            // Генерируем событие
            CampaignStatusChanged::dispatch($campaign, $oldStatus, $campaign->status);

            Log::info("Campaign {$campaign->id} status changed from {$oldStatus} to {$campaign->status}");

        } catch (\Exception $e) {
            Log::error('Error handling campaign status webhook: '.$e->getMessage(), [
                'data' => $data,
                'exception' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Обработка результатов модерации
     */
    public function handleModerationResult(array $data): void
    {
        try {
            $objectType = $data['object_type'] ?? null; // 'campaign', 'ad', 'creative'
            $objectId = $data['object_id'] ?? null;
            $moderationStatus = $data['moderation_status'] ?? null;
            $moderationComment = $data['moderation_comment'] ?? null;

            if (! $objectType || ! $objectId || ! $moderationStatus) {
                Log::warning('Invalid moderation webhook data', $data);

                return;
            }

            $model = $this->findModelByTypeAndVkId($objectType, $objectId);

            if (! $model) {
                Log::warning("Object not found: {$objectType} with VK ID {$objectId}");

                return;
            }

            $oldStatus = $model->moderation_status;
            $model->update([
                'moderation_status' => $moderationStatus,
                'moderation_comment' => $moderationComment,
                'moderated_at' => now(),
                'last_sync_at' => now(),
            ]);

            // Генерируем событие
            ModerationCompleted::dispatch($model, $oldStatus, $moderationStatus);

            Log::info("Moderation completed for {$objectType} {$model->id}: {$moderationStatus}");

        } catch (\Exception $e) {
            Log::error('Error handling moderation webhook: '.$e->getMessage(), [
                'data' => $data,
                'exception' => $e->getTraceAsString(),
            ]);
        }
    }

    private function mapVkStatusToLocal(string $vkStatus): string
    {
        $statusMap = [
            '1' => 'active',
            '0' => 'paused',
            'deleted' => 'deleted',
            'archived' => 'archived',
        ];

        return $statusMap[$vkStatus] ?? 'paused';
    }

    private function findModelByTypeAndVkId(string $objectType, int $vkId): mixed
    {
        return match ($objectType) {
            'campaign' => VkAdsCampaign::where('vk_campaign_id', $vkId)->first(),
            'ad' => VkAdsAd::where('vk_ad_id', $vkId)->first(),
            'creative' => VkAdsCreative::where('vk_creative_id', $vkId)->first(),
            default => null
        };
    }
}
