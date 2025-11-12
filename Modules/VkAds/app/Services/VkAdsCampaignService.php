<?php

namespace Modules\VkAds\app\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\VkAds\app\Exceptions\VkAdsException;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Models\VkAdsAdGroup;
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

    public function createCampaign(int $accountId, array $params = []): VkAdsCampaign
    {
        Log::info('Creating VK Ads campaign', [
            'account_id' => $accountId,
            'params' => $params,
        ]);

        $account = VkAdsAccount::findOrFail($accountId);

        try {

            // 1. Подготавливаем данные для VK API согласно AdPlan
            $vkCampaignData = [
                'name' => $params['name'] ?? 'Кампания от '.now()->locale('ru')->isoFormat('DD MMMM YYYY'),
                'autobidding_mode' => $params['autobidding_mode'] ?? 'second_price_mean', // max_goals
                'budget_limit' => (int) ($params['budget_limit'] ?? 2000),
                'date_start' => $params['date_start'] ?? now()->format('Y-m-d'),
                'date_end' => $params['date_end'] ?? now()->addDays(5)->format('Y-m-d'),
                //                    'max_price' => (int)($params['max_price'] ?? 0),
                'objective' => $params['objective'] ?? 'branding_video',
                'ad_groups' => [[
                    'name' => 'Моя новая группа',
                    'package_id' => $params['package_id'] ?? $this->getDefaultPackageId($account, 'branding_video'),
                ]],
            ];

            Log::info('Creating campaign in VK Ads', ['data' => $vkCampaignData]);

            // 2. СНАЧАЛА создаем в VK Ads
            $vkResponse = $this->apiService->makeAuthenticatedRequest(
                $account,
                'ad_plans',
                $vkCampaignData,
                'POST'
            );

            Log::info('VK Ads campaign created', ['vk_response' => $vkResponse]);

            // 3. ЗАТЕМ сохраняем в БД
            $campaignId = $vkResponse['campaigns']['id'] ?? $vkResponse['id'];

            $campaign = VkAdsCampaign::create([
                'vk_campaign_id' => $campaignId,
                'vk_ads_account_id' => $account->id,
                'name' => $vkCampaignData['name'], // ИСПРАВЛЕНО: из отправленных данных
                'status' => 'active',
                'autobidding_mode' => $vkCampaignData['autobidding_mode'] ?? null,
                'budget_limit' => $vkCampaignData['budget_limit'] ?? null,
                'max_price' => $vkCampaignData['max_price'] ?? null,
                'campaign_type' => $vkCampaignData['objective'] ?? null,
                'objective' => $vkCampaignData['objective'] ?? null,
                'start_date' => $vkCampaignData['date_start'] ?? null,
                'end_date' => $vkCampaignData['date_end'] ?? null,
                'last_sync_at' => now(),
            ]);

            // ДОБАВЛЕНО: если была создана группа объявлений, сохраняем ее в БД
            if (! empty($vkResponse['campaigns'][0]['id']) && ! empty($vkCampaignData['ad_groups'])) {
                $adGroupId = $vkResponse['id']; // ID группы объявлений из ответа VK
                $adGroupData = $vkCampaignData['ad_groups'][0]; // Данные первой группы

                Log::info('Saving created ad group', [
                    'ad_group_id' => $adGroupId,
                    'ad_group_name' => $adGroupData['name'],
                    'package_id' => $adGroupData['package_id'],
                    'campaign_id' => $campaign->id,
                ]);

                // Создаем запись группы объявлений в БД
                $adGroup = VkAdsAdGroup::create([
                    'vk_ad_group_id' => $adGroupId,
                    'vk_ads_account_id' => $account->id,
                    'vk_ads_campaign_id' => $campaign->id,
                    'name' => $adGroupData['name'],
                    'status' => 'paused', // По умолчанию активная
                    'bid' => null, // Будет заполнено при синхронизации
                    'targetings' => [], // Будет заполнено при синхронизации
                    'last_sync_at' => now(),
                ]);

                Log::info('Ad group created successfully', [
                    'id' => $adGroup->id,
                    'vk_ad_group_id' => $adGroup->vk_ad_group_id,
                    'name' => $adGroup->name,
                    'campaign_id' => $adGroup->vk_ads_campaign_id,
                ]);
            }

            Log::info('Campaign created successfully', [
                'id' => $campaign->id,
                'vk_campaign_id' => $campaign->vk_campaign_id,
                'name' => $campaign->name,
            ]);

            return $campaign;

        } catch (\Exception $e) {
            Log::error('Failed to create campaign', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            throw new VkAdsException(
                'Failed to create campaign: '.$e->getMessage(),
                0,
                $e,
                ['account_id' => $account->id]
            );
        }
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
                    'fields' => 'id,name,package_id,status,objective,autobidding_mode,budget_limit,budget_limit_day,max_price,priced_goal,date_start,date_end,package_id,selling_proposition,price',
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
                            'vk_data' => $campaignDetails,
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

    /**
     * Получить доступные пакеты для аккаунта
     */
    private function getAvailablePackages(VkAdsAccount $account): array
    {
        try {
            return $this->apiService->makeAuthenticatedRequest(
                $account,
                'packages', // теперь будет правильно маппиться
                ['fields' => 'id,name,description,objective'],
                'GET'
            );
        } catch (\Exception $e) {
            Log::error('Failed to get packages', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Получить package_id по умолчанию
     */
    private function getDefaultPackageId(VkAdsAccount $account, string $objective): ?int
    {
        return 4214;
        try {
            $packages = $this->apiService->makeAuthenticatedRequest(
                $account,
                'packages',
                [
                    'fields' => 'id,name,description,objective,price',
                ],
                'GET'
            );

            Log::info('Searching package for objective', [
                'objective' => $objective,
                'total_packages' => count($packages),
            ]);

            // ИСПРАВЛЕНО: ищем подходящий package по objective (objective - это массив!)
            foreach ($packages as $package) {
                $packageObjectives = $package['objective'] ?? [];

                // ИСПРАВЛЕНО: проверяем, есть ли наш objective в массиве
                if (is_array($packageObjectives) && in_array($objective, $packageObjectives)) {
                    Log::info('Found package for objective', [
                        'objective' => $objective,
                        'package_id' => $package['id'],
                        'package_name' => $package['name'] ?? 'N/A',
                        'package_objectives' => $packageObjectives,
                    ]);

                    return $package['id'];
                }
            }

            // Если не нашли по objective, выводим список доступных для branding_video
            if ($objective === 'branding_video') {
                $brandingPackages = array_filter($packages, function ($package) {
                    $packageObjectives = $package['objective'] ?? [];

                    return is_array($packageObjectives) && in_array('branding_video', $packageObjectives);
                });

                Log::info('Available branding_video packages', [
                    'count' => count($brandingPackages),
                    'packages' => array_slice($brandingPackages, 0, 3), // Первые 3 для лога
                ]);

                if (! empty($brandingPackages)) {
                    $firstBranding = array_values($brandingPackages)[0];
                    Log::info('Using first branding_video package', [
                        'package_id' => $firstBranding['id'],
                        'package_name' => $firstBranding['name'] ?? 'N/A',
                    ]);

                    return $firstBranding['id'];
                }
            }

            Log::warning('No suitable package found for objective', ['objective' => $objective]);

            return null;

        } catch (\Exception $e) {
            Log::error('Failed to get packages, using fallback', [
                'error' => $e->getMessage(),
                'objective' => $objective,
            ]);

            // Fallback на известные package_id для branding_video
            return 3517; // or_tt_crossdevice_site_instream_cpm_branding_vast
        }
    }

    /**
     * Fallback метод с известными package_id
     */
    private function getFallbackPackageId(string $objective): ?int
    {
        $fallbackPackages = [
            'branding_video' => 61,   // Видеобрендинг
            'reach' => 51,            // Охват
            'traffic' => 41,          // Трафик
            'engagement' => 71,       // Вовлеченность
            'conversions' => 81,      // Конверсии
            'cpc' => 31,              // Клики по сайту
        ];

        $packageId = $fallbackPackages[$objective] ?? $fallbackPackages['branding_video'];

        Log::info('Using fallback package ID', [
            'objective' => $objective,
            'package_id' => $packageId,
        ]);

        return $packageId;
    }
}
