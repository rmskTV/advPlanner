<?php

namespace Modules\VkAds\app\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Models\VkAdsAd;

class VkAdsAdService
{
    private VkAdsApiService $apiService;

    public function __construct(VkAdsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * Получить все объявления для аккаунта
     */
    public function getAds(VkAdsAccount $account): Collection
    {
        return VkAdsAd::whereHas('adGroup', function ($query) use ($account) {
            $query->where('vk_ads_account_id', $account->id);
        })->with(['adGroup.campaign'])->get();
    }

    /**
     * Создать объявление
     */
    public function createAd(VkAdsAdGroup $adGroup, array $params = []): VkAdsAd
    {
        Log::info('Creating VK Ad banner', [
            'ad_group_id' => $adGroup->id,
            'vk_ad_group_id' => $adGroup->vk_ad_group_id,
            'params' => $params,
        ]);

        $account = $adGroup->account;

        try {
            // 1. Подготавливаем URL (если передан)
            $urlId = null;
            if (! empty($params['url'])) {
                $urlId = $this->createUrl($account, $params['url']);
                Log::info('Created URL for ad', ['url_id' => $urlId, 'url' => $params['url']]);
            }

            // 2. Подготавливаем контент (если передан)
            $contentId = null;
            if (! empty($params['content_file']) || ! empty($params['content_url'])) {
                $contentId = $this->prepareContent($account, $params);
                Log::info('Prepared content for ad', ['content_id' => $contentId]);
            }

            // 3. Формируем данные для создания баннера
            $bannerData = [
                'ad_group_id' => $adGroup->vk_ad_group_id,
                'name' => $params['name'] ?? 'Объявление от '.now()->format('d.m.Y H:i'),
            ];

            // Добавляем textblocks (текст и заголовок)
            if (! empty($params['title']) || ! empty($params['text'])) {
                $textblocks = [];
                if (! empty($params['title'])) {
                    $textblocks['title'] = $params['title'];
                }
                if (! empty($params['text'])) {
                    $textblocks['text'] = $params['text'];
                }
                $bannerData['textblocks'] = $textblocks;
            }

            // Добавляем URLs
            if ($urlId) {
                $bannerData['urls'] = [
                    'primary' => $urlId,
                ];
            }

            // Добавляем content
            if ($contentId) {
                $bannerData['content'] = [
                    'id' => $contentId,
                ];
            }

            Log::info('Creating banner in VK Ads', ['data' => $bannerData]);

            // 4. Создаем объявление в VK Ads API
            $vkResponse = $this->apiService->makeAuthenticatedRequest(
                $account,
                'banners',
                [$bannerData], // API принимает массив объявлений
                'POST'
            );

            Log::info('VK Ads banner created', ['vk_response' => $vkResponse]);

            // 5. Сохраняем в БД
            $adId = $vkResponse[0]['id'] ?? $vkResponse['id'];

            $ad = VkAdsAd::create([
                'vk_ad_id' => $adId,
                'vk_ads_ad_group_id' => $adGroup->id,
                'name' => $bannerData['name'],
                'status' => 'active',
                'textblocks' => $bannerData['textblocks'] ?? null,
                'urls' => $bannerData['urls'] ?? null,
                'content' => $bannerData['content'] ?? null,
                'delivery' => 'pending',
                'moderation_status' => 'pending',
                'vk_data' => $vkResponse[0] ?? $vkResponse,
                'last_sync_at' => now(),
            ]);

            Log::info('Banner saved to database', [
                'id' => $ad->id,
                'vk_ad_id' => $ad->vk_ad_id,
                'name' => $ad->name,
            ]);

            return $ad;

        } catch (\Exception $e) {
            Log::error('Failed to create banner', [
                'ad_group_id' => $adGroup->id,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception(
                'Failed to create banner: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Создать и проверить URL для объявления
     */
    public function createUrl(VkAdsAccount $account, string $url): int
    {
        Log::info('Creating URL for ad', ['url' => $url]);

        try {
            $response = $this->apiService->makeAuthenticatedRequest(
                $account,
                'urls',
                ['url' => $url],
                'POST'
            );

            $urlId = $response['id'];
            Log::info('URL created successfully', ['url_id' => $urlId, 'url' => $url]);

            return $urlId;

        } catch (\Exception $e) {
            Log::error('Failed to create URL', ['url' => $url, 'error' => $e->getMessage()]);
            throw new \Exception('Failed to create URL: '.$e->getMessage());
        }
    }

    /**
     * Подготовить контент для объявления
     */
    private function prepareContent(VkAdsAccount $account, array $params): ?int
    {
        // Если передан файл для загрузки
        if (! empty($params['content_file'])) {
            return $this->uploadContent($account, $params);
        }

        // Если передан готовый content_id
        if (! empty($params['content_id'])) {
            return (int) $params['content_id'];
        }

        return null;
    }

    /**
     * Загрузить контент (изображение/видео)
     */
    public function uploadContent(VkAdsAccount $account, array $params): int
    {
        $contentType = $params['content_type'] ?? 'static'; // static, video, html5
        $filePath = $params['content_file'];

        if (! file_exists($filePath)) {
            throw new \Exception("Content file not found: {$filePath}");
        }

        Log::info('Uploading content', [
            'type' => $contentType,
            'file' => basename($filePath),
        ]);

        try {
            // Определяем endpoint в зависимости от типа контента
            $endpoint = match ($contentType) {
                'static' => 'content/static',
                'video' => 'content/video',
                'html5' => 'content/html5',
                default => 'content/static'
            };

            // Подготавливаем данные
            $data = [
                'file' => new \CURLFile($filePath),
            ];

            // Для статичных изображений и видео нужны размеры
            if (in_array($contentType, ['static', 'video'])) {
                $data['data'] = json_encode([
                    'width' => $params['width'] ?? 728,
                    'height' => $params['height'] ?? 90,
                ]);
            }

            // Используем прямой HTTP запрос для multipart/form-data
            $response = $this->uploadContentViaHttp($account, $endpoint, $data);

            $contentId = $response['id'];
            Log::info('Content uploaded successfully', [
                'content_id' => $contentId,
                'type' => $contentType,
            ]);

            return $contentId;

        } catch (\Exception $e) {
            Log::error('Failed to upload content', [
                'type' => $contentType,
                'file' => basename($filePath),
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Failed to upload content: '.$e->getMessage());
        }
    }

    /**
     * Загрузка контента через HTTP (для multipart/form-data)
     */
    private function uploadContentViaHttp(VkAdsAccount $account, string $endpoint, array $data): array
    {
        $token = $account->getValidToken();

        if (! $token) {
            throw new \Exception('No valid token found for account');
        }

        $url = config('vkads.api.base_url', 'https://ads.vk.com/api/v2/').$endpoint.'.json';

        // Используем cURL для multipart запроса
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token->access_token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL error: '.$error);
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new \Exception("HTTP error {$httpCode}: ".$response);
        }

        $responseData = json_decode($response, true);

        if (isset($responseData['error'])) {
            throw new \Exception('VK API error: '.json_encode($responseData['error']));
        }

        return $responseData;
    }

    /**
     * Синхронизировать объявления для групп объявлений
     */
    public function syncAdsForAdGroups(VkAdsAccount $account, Collection $adGroups): Collection
    {
        try {
            if ($adGroups->isEmpty()) {
                Log::info('No ad groups to sync ads for');

                // ИСПРАВЛЕНО: возвращаем пустую Eloquent Collection
                return new Collection;
            }

            $adGroupIds = $adGroups->pluck('vk_ad_group_id')->toArray();

            Log::info('Syncing ads for ad groups', [
                'account_id' => $account->id,
                'ad_group_ids' => $adGroupIds,
            ]);

            // Запрашиваем объявления (banners) согласно VK Ads API
            $vkAds = $this->apiService->makeAuthenticatedRequest($account, 'banners', [
                'ad_group_id__in' => implode(',', $adGroupIds),
                'fields' => 'id,name,status,ad_group_id,content,delivery,issues,moderation_status,moderation_reasons,textblocks,urls,ord_marker,created,updated',
            ]);

            Log::info('Received ads from VK', [
                'count' => count($vkAds),
                'sample_fields' => ! empty($vkAds) ? array_keys($vkAds[0]) : [],
            ]);

            // ИСПРАВЛЕНО: используем массив для сбора объектов, затем создаем Eloquent Collection
            $syncedAds = [];

            foreach ($vkAds as $vkAd) {
                try {
                    // Ищем группу объявлений
                    $adGroup = $adGroups->firstWhere('vk_ad_group_id', $vkAd['ad_group_id'] ?? null);

                    if (! $adGroup) {
                        Log::warning('Ad group not found for ad', [
                            'ad_id' => $vkAd['id'],
                            'ad_group_id' => $vkAd['ad_group_id'] ?? 'missing',
                        ]);

                        continue;
                    }

                    $ad = VkAdsAd::updateOrCreate([
                        'vk_ad_id' => $vkAd['id'],
                    ], [
                        'vk_ads_ad_group_id' => $adGroup->id,
                        'name' => $vkAd['name'],
                        'status' => $this->mapVkStatus($vkAd['status'] ?? 'active'),
                        'content' => $vkAd['content'] ?? null,
                        'delivery' => $this->mapDeliveryStatus($vkAd['delivery'] ?? 'pending'),
                        'issues' => $vkAd['issues'] ?? null,
                        'moderation_status' => $this->mapModerationStatus($vkAd['moderation_status'] ?? 'pending'),
                        'moderation_reasons' => $vkAd['moderation_reasons'] ?? null,
                        'textblocks' => $vkAd['textblocks'] ?? null,
                        'urls' => $vkAd['urls'] ?? null,
                        'ord_marker' => $vkAd['ord_marker'] ?? null,
                        'created_at_vk' => $this->parseVkDateTime($vkAd['created'] ?? null),
                        'updated_at_vk' => $this->parseVkDateTime($vkAd['updated'] ?? null),
                        'vk_data' => $vkAd,
                        'last_sync_at' => now(),
                    ]);

                    // ИСПРАВЛЕНО: добавляем в массив
                    $syncedAds[] = $ad;

                    Log::info('Synced ad', [
                        'vk_ad_id' => $vkAd['id'],
                        'name' => $vkAd['name'],
                        'ad_group_id' => $adGroup->id,
                        'status' => $ad->status,
                        'delivery' => $ad->delivery,
                        'moderation_status' => $ad->moderation_status,
                    ]);

                } catch (\Exception $e) {
                    Log::warning("Failed to sync ad {$vkAd['id']}: ".$e->getMessage());
                }
            }

            // ИСПРАВЛЕНО: создаем Eloquent Collection из массива
            $result = new Collection($syncedAds);

            Log::info('Successfully synced ads', ['count' => $result->count()]);

            return $result;

        } catch (\Exception $e) {
            Log::warning('Failed to sync ads for ad groups: '.$e->getMessage());

            // ИСПРАВЛЕНО: возвращаем пустую Eloquent Collection
            return new Collection;
        }
    }

    /**
     * Маппинг статуса VK в наш формат
     */
    private function mapVkStatus($vkStatus): string
    {
        return match ($vkStatus) {
            'active' => VkAdsAd::STATUS_ACTIVE,
            'deleted' => VkAdsAd::STATUS_DELETED,
            'blocked' => VkAdsAd::STATUS_BLOCKED,
            default => VkAdsAd::STATUS_ACTIVE
        };
    }

    /**
     * Маппинг статуса трансляции
     */
    private function mapDeliveryStatus($vkDelivery): string
    {
        return match ($vkDelivery) {
            'pending' => VkAdsAd::DELIVERY_PENDING,
            'delivering' => VkAdsAd::DELIVERY_DELIVERING,
            'not_delivering' => VkAdsAd::DELIVERY_NOT_DELIVERING,
            default => VkAdsAd::DELIVERY_PENDING
        };
    }

    /**
     * Маппинг статуса модерации
     */
    private function mapModerationStatus($vkStatus): string
    {
        return match ($vkStatus) {
            'pending' => VkAdsAd::MODERATION_PENDING,
            'allowed' => VkAdsAd::MODERATION_ALLOWED,
            'banned' => VkAdsAd::MODERATION_BANNED,
            default => VkAdsAd::MODERATION_PENDING
        };
    }

    /**
     * Парсинг даты/времени VK
     */
    private function parseVkDateTime($vkDateTime): ?\Carbon\Carbon
    {
        if (! $vkDateTime) {
            return null;
        }

        try {
            if (is_numeric($vkDateTime)) {
                return \Carbon\Carbon::createFromTimestamp($vkDateTime);
            }

            return \Carbon\Carbon::parse($vkDateTime);
        } catch (\Exception $e) {
            Log::warning("Failed to parse VK datetime: {$vkDateTime}");

            return null;
        }
    }
}
