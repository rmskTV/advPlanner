<?php

namespace Modules\VkAds\app\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Models\VkAdsCampaign;

class VkAdsCampaignService
{
    private VkAdsApiService $apiService;

    public function __construct(VkAdsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    public function getCampaigns(VkAdsAccount $account): Collection
    {
        return VkAdsCampaign::where('vk_ads_account_id', $account->id)
            ->with('adGroups.orderItem')
            ->get();
    }

    public function getCampaignDetails(VkAdsAccount $account, int $campaignId): ?array
    {
        try {
            Log::info('Getting campaign details', [
                'campaign_id' => $campaignId,
                'account_id' => $account->id,
            ]);

            // ИСПРАВЛЕНО: запрашиваем все нужные поля
            $campaignDetails = $this->apiService->makeAuthenticatedRequest(
                $account,
                "ad_plans/{$campaignId}.json",
                [
                    'fields' => 'id,name,status,objective,autobidding_mode,budget_limit,budget_limit_day,max_price,priced_goal,date_start,date_end,package_id,selling_proposition,price',
                ]
            );

            Log::info('Received campaign details', [
                'campaign_id' => $campaignId,
                'name' => $campaignDetails['name'] ?? 'N/A',
                'status' => $campaignDetails['status'] ?? 'N/A',
                'objective' => $campaignDetails['objective'] ?? 'N/A',
                'autobidding_mode' => $campaignDetails['autobidding_mode'] ?? 'N/A',
            ]);

            return $campaignDetails;

        } catch (\Exception $e) {
            Log::warning("Failed to get campaign details for {$campaignId}: ".$e->getMessage());

            return null;
        }
    }

    public function syncAllCampaigns(VkAdsAccount $account): Collection
    {
        try {
            Log::info('Syncing campaigns for account', [
                'account_id' => $account->id,
                'vk_account_id' => $account->vk_account_id,
                'account_type' => $account->account_type,
            ]);

            // 1. Получаем список кампаний
            $vkCampaignsList = $this->apiService->makeAuthenticatedRequest($account, 'ad_plans');
            Log::info('Received campaigns list from VK', ['count' => count($vkCampaignsList)]);

            $syncedCampaigns = [];

            foreach ($vkCampaignsList as $campaignItem) {
                try {
                    // 2. Получаем детальную информацию о каждой кампании
                    $campaignDetails = $this->getCampaignDetails($account, $campaignItem['id']);

                    if ($campaignDetails) {
                        // ДОБАВЛЕНО: логирование данных перед сохранением
                        Log::info('Processing campaign data', [
                            'campaign_id' => $campaignDetails['id'],
                            'priced_goal_type' => gettype($campaignDetails['priced_goal'] ?? null),
                            'priced_goal_value' => $campaignDetails['priced_goal'] ?? null,
                        ]);

                        $campaign = VkAdsCampaign::updateOrCreate([
                            'vk_campaign_id' => $campaignDetails['id'],
                        ], [
                            'vk_ads_account_id' => $account->id,
                            'name' => $campaignDetails['name'],
                            'description' => $campaignDetails['selling_proposition'] ?? null,
                            'status' => $this->mapVkStatus($campaignDetails['status'] ?? 'active'),
                            'campaign_type' => $campaignDetails['objective'] ?? 'unknown',

                            // Новые поля
                            'autobidding_mode' => $campaignDetails['autobidding_mode'] ?? null,
                            'budget_limit' => $campaignDetails['budget_limit'] ?? null,
                            'budget_limit_day' => $campaignDetails['budget_limit_day'] ?? null,
                            'max_price' => $campaignDetails['max_price'] ?? null,
                            'objective' => $campaignDetails['objective'] ?? null,

                            // ИСПРАВЛЕНО: priced_goal как JSON строка, если это объект/массив
                            'priced_goal' => is_array($campaignDetails['priced_goal'] ?? null)
                                ? json_encode($campaignDetails['priced_goal'])
                                : $campaignDetails['priced_goal'] ?? null,

                            // Дублируем в старые поля для совместимости
                            'daily_budget' => $campaignDetails['budget_limit_day'] ?? null,
                            'total_budget' => $campaignDetails['budget_limit'] ?? null,
                            'start_date' => $this->parseVkDate($campaignDetails['date_start'] ?? null),
                            'end_date' => $this->parseVkDate($campaignDetails['date_end'] ?? null),
                            'last_sync_at' => now(),
                        ]);

                        $syncedCampaigns[] = $campaign;

                        Log::info('Synced campaign', [
                            'id' => $campaign->id,
                            'vk_campaign_id' => $campaign->vk_campaign_id,
                            'name' => $campaign->name,
                            'status' => $campaign->status,
                            'objective' => $campaign->objective,
                            'autobidding_mode' => $campaign->autobidding_mode,
                            'budget_limit_day' => $campaign->budget_limit_day,
                            'priced_goal_saved' => ! empty($campaign->priced_goal),
                        ]);
                    }

                } catch (\Exception $e) {
                    Log::warning("Failed to sync campaign {$campaignItem['id']}: ".$e->getMessage());

                    // ДОБАВЛЕНО: логирование стека ошибки для диагностики
                    Log::debug('Campaign sync error details', [
                        'campaign_id' => $campaignItem['id'],
                        'error_trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            Log::info('Successfully synced campaigns', ['count' => count($syncedCampaigns)]);

        } catch (\Exception $e) {
            Log::warning("Failed to sync campaigns for account {$account->id}: ".$e->getMessage());
        }

        return $this->getCampaigns($account);
    }

    private function mapVkStatus($vkStatus): string
    {
        return match ($vkStatus) {
            1, 'active' => 'active',
            0, 'paused' => 'paused',
            'deleted' => 'deleted',
            default => 'paused'
        };
    }

    private function parseVkDate($vkDate): ?string
    {
        if (! $vkDate) {
            return null;
        }

        // VK может возвращать timestamp или строку даты
        if (is_numeric($vkDate)) {
            return date('Y-m-d', $vkDate);
        }

        if (is_string($vkDate)) {
            try {
                return date('Y-m-d', strtotime($vkDate));
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }
}
