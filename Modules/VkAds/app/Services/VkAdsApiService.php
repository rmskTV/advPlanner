<?php

namespace Modules\VkAds\app\Services;

use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Models\VkAdsToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VkAdsApiService
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;

    public function __construct()
    {
        $this->baseUrl = config('vkads.api.base_url', 'https://ads.vk.com/api/v2/');
        $this->clientId = config('vkads.client_id') ?? env('VK_ADS_CLIENT_ID');
        $this->clientSecret = config('vkads.client_secret') ?? env('VK_ADS_CLIENT_SECRET');

        if (!$this->clientId || !$this->clientSecret) {
            throw new \Exception('VK_ADS_CLIENT_ID and VK_ADS_CLIENT_SECRET must be set in .env');
        }
    }

    public function makeAuthenticatedRequest(VkAdsAccount $account, string $endpoint, array $params = []): array
    {
        $token = $this->getValidTokenForAccount($account);

        // Правильные endpoints VK Ads API
        $endpointMap = [
            'ad_plans' => 'ad_plans.json',
            'ad_groups' => 'ad_groups.json',
            'ads' => 'ads.json',
            'banners' => 'banners.json',
            'creatives' => 'creatives.json',
            'agency/clients' => 'agency/clients.json',
        ];

        $actualEndpoint = $endpointMap[$endpoint] ?? $endpoint;
        $url = $this->baseUrl . $actualEndpoint;

        // Для индивидуальных запросов (например ad_plans/123.json) не добавляем account_id
        $isIndividualRequest = str_contains($endpoint, '/') && str_contains($endpoint, '.json');
        $isAgencyClientsRequest = $endpoint === 'agency/clients';

        if (!$isIndividualRequest && !$isAgencyClientsRequest) {
            $params['account_id'] = $account->vk_account_id;
        }

        // ДОБАВЛЕНО: проверяем, нужна ли пагинация
        $allItems = [];
        $offset = 0;
        $limit = $params['limit'] ?? 100; // VK Ads API обычно поддерживает до 100 элементов за запрос
        $maxIterations = 50; // Защита от бесконечного цикла
        $iteration = 0;

        do {
            $iteration++;
            $currentParams = array_merge($params, [
                'offset' => $offset,
                'limit' => $limit
            ]);

            Log::info('Making VK Ads API request', [
                'url' => $url,
                'endpoint' => $endpoint,
                'is_individual' => $isIndividualRequest,
                'account_id' => $account->vk_account_id,
                'params' => $currentParams,
                'iteration' => $iteration,
                'offset' => $offset
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->timeout(30)->get($url, $currentParams);

            Log::info('VK Ads API response', [
                'status' => $response->status(),
                'body_preview' => substr($response->body(), 0, 300) . '...',
                'iteration' => $iteration
            ]);

            if ($response->status() === 401) {
                Log::info('Token expired, refreshing...');
                $token = $this->unactivateTokenForAccount($account);
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ])->get($url, $currentParams);
            }

            if (!$response->successful()) {
                throw new \Exception("VK Ads API error: {$response->status()} - {$response->body()}");
            }

            $data = $response->json();

            if (isset($data['error'])) {
                throw new \Exception("VK Ads API error: " . ($data['error']['error_msg'] ?? $data['error']['message'] ?? 'Unknown error'));
            }

            // Для индивидуальных запросов возвращаем весь объект
            if ($isIndividualRequest) {
                return $data;
            }

            $items = $data['items'] ?? $data['response'] ?? $data;
            $totalCount = $data['count'] ?? count($items);

            // ДОБАВЛЕНО: логирование пагинации
            Log::info('VK Ads API pagination info', [
                'iteration' => $iteration,
                'current_items' => count($items),
                'total_count' => $totalCount,
                'offset' => $offset,
                'collected_so_far' => count($allItems)
            ]);

            // Добавляем полученные элементы к общему списку
            $allItems = array_merge($allItems, $items);

            // Увеличиваем offset для следующей итерации
            $offset += count($items);

            // ИСПРАВЛЕНО: правильная проверка условий для продолжения пагинации
            $hasMoreItems = count($items) === $limit && count($allItems) < $totalCount;

            Log::info('VK Ads API pagination decision', [
                'has_more_items' => $hasMoreItems,
                'items_in_response' => count($items),
                'limit' => $limit,
                'total_collected' => count($allItems),
                'total_count' => $totalCount,
                'will_continue' => $hasMoreItems && $iteration < $maxIterations
            ]);

        } while ($hasMoreItems && $iteration < $maxIterations);

        if ($iteration >= $maxIterations) {
            Log::warning('VK Ads API pagination stopped due to max iterations limit', [
                'endpoint' => $endpoint,
                'max_iterations' => $maxIterations,
                'collected_items' => count($allItems),
                'expected_total' => $totalCount ?? 'unknown'
            ]);
        }

        Log::info('VK Ads API pagination completed', [
            'endpoint' => $endpoint,
            'total_iterations' => $iteration,
            'final_count' => count($allItems),
            'expected_count' => $totalCount ?? 'unknown'
        ]);

        return $allItems;
    }

    /**
     * ДОБАВЛЕНО: метод для запросов без пагинации (если нужно)
     */
    public function makeAuthenticatedRequestSingle(VkAdsAccount $account, string $endpoint, array $params = []): array
    {
        $token = $this->getValidTokenForAccount($account);

        $endpointMap = [
            'ad_plans' => 'ad_plans.json',
            'ad_groups' => 'ad_groups.json',
            'ads' => 'ads.json',
            'banners' => 'banners.json',
            'creatives' => 'creatives.json',
            'agency/clients' => 'agency/clients.json',
        ];

        $actualEndpoint = $endpointMap[$endpoint] ?? $endpoint;
        $url = $this->baseUrl . $actualEndpoint;

        $isIndividualRequest = str_contains($endpoint, '/') && str_contains($endpoint, '.json');
        $isAgencyClientsRequest = $endpoint === 'agency/clients';

        if (!$isIndividualRequest && !$isAgencyClientsRequest) {
            $params['account_id'] = $account->vk_account_id;
        }

        Log::info('Making single VK Ads API request', [
            'url' => $url,
            'endpoint' => $endpoint,
            'params' => $params
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ])->timeout(30)->get($url, $params);

        if ($response->status() === 401) {
            $token = $this->unactivateTokenForAccount($account);
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->get($url, $params);
        }

        if (!$response->successful()) {
            throw new \Exception("VK Ads API error: {$response->status()} - {$response->body()}");
        }

        $data = $response->json();

        if (isset($data['error'])) {
            throw new \Exception("VK Ads API error: " . ($data['error']['error_msg'] ?? $data['error']['message'] ?? 'Unknown error'));
        }

        if ($isIndividualRequest) {
            return $data;
        }

        return $data['items'] ?? $data['response'] ?? $data;
    }
    /**
     * Основной метод для API запросов
     */
    public function makeRequest(VkAdsAccount $account, string $method, string $endpoint, array $params = []): array
    {
        $token = $this->getValidTokenForAccount($account);

        $url = $this->baseUrl . $endpoint;

        // Добавляем account_id в параметры для большинства запросов
        if (!in_array($endpoint, ['agency/clients', 'oauth2/token'])) {
            $params['account_id'] = $account->vk_account_id;
        }

        Log::info('Making VK Ads API request', [
            'method' => $method,
            'url' => $url,
            'account_id' => $account->vk_account_id,
            'params_count' => count($params)
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ])->timeout(30);

        // Выполняем запрос в зависимости от метода
        switch ($method) {
            case 'GET':
                $response = $response->get($url, $params);
                break;
            case 'POST':
                $response = $response->post($url, $params);
                break;
            case 'PUT':
                $response = $response->put($url, $params);
                break;
            case 'DELETE':
                $response = $response->delete($url, $params);
                break;
            default:
                throw new \Exception("Unsupported HTTP method: {$method}");
        }

        Log::info('VK Ads API response', [
            'status' => $response->status(),
            'success' => $response->successful()
        ]);

        if ($response->status() === 401) {
            Log::info('Token expired, refreshing...');
            $token = $this->unactivateTokenForAccount($account);

            // Повторяем запрос с новым токеном
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ]);

            switch ($method) {
                case 'GET':
                    $response = $response->get($url, $params);
                    break;
                case 'POST':
                    $response = $response->post($url, $params);
                    break;
            }
        }

        if (!$response->successful()) {
            throw new \Exception("VK Ads API error: {$response->status()} - {$response->body()}");
        }

        $data = $response->json();

        if (isset($data['error'])) {
            throw new \Exception("VK Ads API error: " . ($data['error']['error_msg'] ?? $data['error']['message'] ?? 'Unknown error'));
        }

        return $data['response'] ?? $data;
    }

    /**
     * Получить валидный токен для аккаунта
     */
    private function getValidTokenForAccount(VkAdsAccount $account): string
    {
        Log::info("Getting valid token for account", [
            'account_id' => $account->id,
            'account_type' => $account->account_type,
            'vk_account_id' => $account->vk_account_id
        ]);

        // Сначала ищем активный токен
        $token = $account->tokens()
            ->where('is_active', true)
            ->orderBy('expires_at', 'desc')
            ->first();

        if (!$token) {
            Log::info("No token found, creating new one");
            return $this->createTokenForAccount($account);
        }

        // Если токен еще валиден - используем его
        if ($token->expires_at > now()) {
            Log::info("Found valid token", [
                'token_id' => $token->id,
                'expires_at' => $token->expires_at,
                'minutes_until_expiry' => now()->diffInMinutes($token->expires_at)
            ]);
            return $token->access_token;
        }

        // ИСПРАВЛЕНО: если токен истек - пытаемся его обновить
        Log::info("Token expired, attempting to refresh", [
            'token_id' => $token->id,
            'expired_at' => $token->expires_at
        ]);

        try {
            return $this->refreshToken($token);
        } catch (\Exception $e) {
            Log::warning("Failed to refresh token, creating new one", [
                'error' => $e->getMessage(),
                'token_id' => $token->id
            ]);

            // Если обновление не удалось - удаляем старый токен и создаем новый
            $token->delete();
            return $this->createTokenForAccount($account);
        }
    }

    /**
     * Создать токен для аккаунта
     */
    private function createTokenForAccount(VkAdsAccount $account): string
    {
        Log::info("Creating token for account", [
            'account_id' => $account->id,
            'account_type' => $account->account_type
        ]);

        if ($account->isAgency()) {
            return $this->createAgencyToken($account);
        } else {
            $agencyAccount = $this->getAgencyAccount();
            return $this->createClientToken($agencyAccount, $account);
        }
    }

    /**
     * Создать агентский токен (client_credentials)
     */
    private function createAgencyToken(VkAdsAccount $account): string
    {
        Log::info("Creating agency token for account ID={$account->id}");

        if (!$this->clientId || !$this->clientSecret) {
            throw new \Exception("VK Ads credentials not configured. Check VK_ADS_CLIENT_ID and VK_ADS_CLIENT_SECRET in .env");
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded'
        ])->asForm()->post($this->baseUrl . 'oauth2/token.json', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        Log::info('Agency token response', [
            'status' => $response->status(),
            'body_preview' => substr($response->body(), 0, 200)
        ]);

        if (!$response->successful()) {
            throw new \Exception("Failed to get agency token: " . $response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            throw new \Exception('VK Ads agency auth error: ' . json_encode($data['error']));
        }

        // ИСПРАВЛЕНО: деактивируем старые токены перед созданием нового
        $account->tokens()->where('token_type', 'agency')->update(['is_active' => false]);

        $token = VkAdsToken::create([
            'vk_ads_account_id' => $account->id,
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null, // ДОБАВЛЕНО: сохраняем refresh_token
            'token_type' => 'agency',
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 86400),
            'is_active' => true
        ]);

        Log::info('Agency token created successfully', [
            'token_id' => $token->id,
            'has_refresh_token' => !empty($token->refresh_token),
            'expires_at' => $token->expires_at
        ]);

        return $token->access_token;
    }

    /**
     * Создать клиентский токен (agency_client_credentials)
     */
    private function createClientToken(VkAdsAccount $agencyAccount, VkAdsAccount $clientAccount): string
    {
        Log::info("Creating client token for account {$clientAccount->vk_account_id}");

        if (!$clientAccount->vk_user_id) {
            throw new \Exception("Client account {$clientAccount->id} missing vk_user_id. Please sync agency clients first.");
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded'
        ])->asForm()->post($this->baseUrl . 'oauth2/token.json', [
            'grant_type' => 'agency_client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'agency_client_id' => $clientAccount->vk_user_id,
        ]);

        if (!$response->successful()) {
            // Пробуем с agency_client_name
            $response = Http::withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded'
            ])->asForm()->post($this->baseUrl . 'oauth2/token.json', [
                'grant_type' => 'agency_client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'agency_client_name' => $clientAccount->vk_username,
            ]);
        }

        if (!$response->successful()) {
            throw new \Exception("Failed to get client token for {$clientAccount->vk_account_id}: " . $response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            throw new \Exception('VK Ads client auth error: ' . json_encode($data['error']));
        }

        // ИСПРАВЛЕНО: деактивируем старые токены перед созданием нового
        $clientAccount->tokens()->update(['is_active' => false]);

        $token = VkAdsToken::create([
            'vk_ads_account_id' => $clientAccount->id,
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null, // ДОБАВЛЕНО: сохраняем refresh_token
            'token_type' => 'client',
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 86400),
            'is_active' => true
        ]);

        Log::info('Client token created successfully', [
            'token_id' => $token->id,
            'has_refresh_token' => !empty($token->refresh_token),
            'expires_at' => $token->expires_at
        ]);

        return $token->access_token;
    }

    private function unactivateTokenForAccount(VkAdsAccount $account): string
    {
        $account->tokens()->update(['is_active' => false]);
        return $this->createTokenForAccount($account);
    }

    private function refreshToken(VkAdsToken $token): string
    {
        Log::info("Refreshing token", [
            'token_id' => $token->id,
            'token_type' => $token->token_type,
            'account_id' => $token->vk_ads_account_id
        ]);

        if (!$token->refresh_token) {
            throw new \Exception("No refresh token available for token {$token->id}");
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded'
        ])->asForm()->post($this->baseUrl . 'oauth2/token.json', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refresh_token,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        Log::info('Token refresh response', [
            'status' => $response->status(),
            'body_preview' => substr($response->body(), 0, 200)
        ]);

        if (!$response->successful()) {
            throw new \Exception("Failed to refresh token: " . $response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            throw new \Exception('VK Ads token refresh error: ' . json_encode($data['error']));
        }

        // ИСПРАВЛЕНО: обновляем существующий токен, а не создаем новый
        $token->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $token->refresh_token, // сохраняем старый если новый не пришел
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 86400),
        ]);

        Log::info('Token refreshed successfully', [
            'token_id' => $token->id,
            'new_expires_at' => $token->expires_at
        ]);

        return $token->access_token;
    }


    private function getAgencyAccount(): VkAdsAccount
    {
        $agencyAccount = VkAdsAccount::where('account_type', 'agency')->where('id', 1)->first();

        if (!$agencyAccount) {
            Log::error('Agency account not found');
            throw new \Exception('Agency account not found');
        }

        Log::info('Found agency account', [
            'id' => $agencyAccount->id,
            'name' => $agencyAccount->account_name,
            'vk_account_id' => $agencyAccount->vk_account_id
        ]);

        return $agencyAccount;
    }


    /**
     * Проверка наличия refresh token
     */
    public function hasRefreshToken(): bool
    {
        return !empty($this->refresh_token);
    }
}
