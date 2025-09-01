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

        // ИСПРАВЛЕНО: правильные endpoints VK Ads API
        $endpointMap = [
            'ad_plans' => 'ad_plans.json',           // список кампаний
            'ad_groups' => 'ad_groups.json',         // группы объявлений
            'ads' => 'ads.json',                     // объявления
            'creatives' => 'creatives.json',         // креативы
            'agency/clients' => 'agency/clients.json', // клиенты агентства
        ];

        // Для индивидуальных запросов (например ad_plans/123.json) используем как есть
        $actualEndpoint = $endpointMap[$endpoint] ?? $endpoint;
        $url = $this->baseUrl . $actualEndpoint;

        // ИСПРАВЛЕНО: account_id добавляем только для списочных запросов
        $isIndividualRequest = str_contains($endpoint, '/') && str_contains($endpoint, '.json');
        $isAgencyClientsRequest = $endpoint === 'agency/clients';

        if (!$isIndividualRequest && !$isAgencyClientsRequest) {
            $params['account_id'] = $account->vk_account_id;
        }

        Log::info('Making VK Ads API request', [
            'url' => $url,
            'endpoint' => $endpoint,
            'is_individual' => $isIndividualRequest,
            'account_id' => $account->vk_account_id,
            'params' => $params
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ])->timeout(30)->get($url, $params);

        Log::info('VK Ads API response', [
            'status' => $response->status(),
            'body_preview' => substr($response->body(), 0, 300) . '...'
        ]);

        if ($response->status() === 401) {
            Log::info('Token expired, refreshing...');
            $token = $this->refreshTokenForAccount($account);
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

        // Для индивидуальных запросов возвращаем весь объект, для списков - items
        if ($isIndividualRequest) {
            return $data; // индивидуальный объект кампании
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
            $token = $this->refreshTokenForAccount($account);

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

        $token = $account->getValidToken();

        if ($token) {
            Log::info("Found valid token", [
                'token_id' => $token->id,
                'token_type' => $token->token_type,
                'expires_at' => $token->expires_at,
                'is_expired' => $token->expires_at < now()
            ]);
            return $token->access_token;
        }

        Log::info("No valid token found, creating new one");
        return $this->createTokenForAccount($account);
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

        // ДОБАВЛЕНО: детальное логирование параметров запроса
        Log::info("Agency token request parameters", [
            'url' => $this->baseUrl . 'oauth2/token.json',
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret_set' => !empty($this->clientSecret),
            'client_secret_length' => strlen($this->clientSecret ?? ''),
            'client_secret_preview' => $this->clientSecret ? substr($this->clientSecret, 0, 8) . '...' : 'NOT SET'
        ]);

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
            'headers' => $response->headers(),
            'body' => $response->body(),
            'successful' => $response->successful()
        ]);

        if (!$response->successful()) {
            // ДОБАВЛЕНО: детальное логирование ошибки
            Log::error('Agency token creation failed - detailed info', [
                'status' => $response->status(),
                'reason' => $response->reason(),
                'body' => $response->body(),
                'headers' => $response->headers(),
                'request_url' => $this->baseUrl . 'oauth2/token.json',
                'client_id' => $this->clientId,
                'client_secret_exists' => !empty($this->clientSecret),
                'client_secret_length' => strlen($this->clientSecret ?? ''),
            ]);

            throw new \Exception("Failed to get agency token: " . $response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            Log::error('Agency token API error', [
                'error' => $data['error'],
                'full_response' => $data
            ]);
            throw new \Exception('VK Ads agency auth error: ' . json_encode($data['error']));
        }

        // ДОБАВЛЕНО: логирование успешного получения токена
        Log::info('Agency token received successfully', [
            'expires_in' => $data['expires_in'] ?? 'not_set',
            'token_type' => $data['token_type'] ?? 'not_set',
            'token_length' => strlen($data['access_token'] ?? ''),
            'token_preview' => isset($data['access_token']) ? substr($data['access_token'], 0, 20) . '...' : 'not_set'
        ]);

        // Деактивируем старые агентские токены
        $deactivatedCount = $account->tokens()->where('token_type', 'agency')->update(['is_active' => false]);
        Log::info("Deactivated old agency tokens", ['count' => $deactivatedCount]);

        $token = VkAdsToken::create([
            'vk_ads_account_id' => $account->id,
            'access_token' => $data['access_token'],
            'token_type' => 'agency',
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 86400),
            'is_active' => true
        ]);

        Log::info('Agency token saved to database', [
            'token_id' => $token->id,
            'account_id' => $account->id,
            'expires_at' => $token->expires_at
        ]);

        return $token->access_token;
    }

    /**
     * Создать клиентский токен (agency_client_credentials)
     */
    private function createClientToken(VkAdsAccount $agencyAccount, VkAdsAccount $clientAccount): string {
        Log::info("Creating client token for account {$clientAccount->vk_account_id}");

        if (!$clientAccount->vk_user_id) {
            throw new \Exception("Client account {$clientAccount->id} missing vk_user_id. Please sync agency clients first.");
        }

        Log::info("Using client credentials", [
            'vk_account_id' => $clientAccount->vk_account_id,
            'vk_user_id' => $clientAccount->vk_user_id,
            'vk_username' => $clientAccount->vk_username
        ]);

        // ДОБАВЛЕНО: детальное логирование параметров клиентского токена
        Log::info("Client token request parameters", [
            'url' => $this->baseUrl . 'oauth2/token.json',
            'grant_type' => 'agency_client_credentials',
            'client_id' => $this->clientId,
            'client_secret_set' => !empty($this->clientSecret),
            'agency_client_id' => $clientAccount->vk_user_id
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded'
        ])->asForm()->post($this->baseUrl . 'oauth2/token.json', [
            'grant_type' => 'agency_client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'agency_client_id' => $clientAccount->vk_user_id,
        ]);

        Log::info('Client token response', [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
            'successful' => $response->successful()
        ]);

        if (!$response->successful()) {
            Log::info('Trying with agency_client_name instead of agency_client_id');

            $response = Http::withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded'
            ])->asForm()->post($this->baseUrl . 'oauth2/token.json', [
                'grant_type' => 'agency_client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'agency_client_name' => $clientAccount->vk_username,
            ]);

            Log::info('Client token response (with name)', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
        }

        if (!$response->successful()) {
            // ДОБАВЛЕНО: детальное логирование ошибки клиентского токена
            Log::error('Client token creation failed - detailed info', [
                'status' => $response->status(),
                'reason' => $response->reason(),
                'body' => $response->body(),
                'headers' => $response->headers(),
                'client_account_id' => $clientAccount->id,
                'vk_account_id' => $clientAccount->vk_account_id,
                'vk_user_id' => $clientAccount->vk_user_id,
                'vk_username' => $clientAccount->vk_username
            ]);

            throw new \Exception("Failed to get client token for {$clientAccount->vk_account_id}: " . $response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            Log::error('Client token API error', [
                'error' => $data['error'],
                'full_response' => $data
            ]);
            throw new \Exception('VK Ads client auth error: ' . json_encode($data['error']));
        }

        // Деактивируем старые клиентские токены
        $deactivatedCount = $clientAccount->tokens()->update(['is_active' => false]);
        Log::info("Deactivated old client tokens", ['count' => $deactivatedCount]);

        $token = VkAdsToken::create([
            'vk_ads_account_id' => $clientAccount->id,
            'access_token' => $data['access_token'],
            'token_type' => 'client',
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 86400),
            'is_active' => true
        ]);

        Log::info('Client token saved to database', [
            'token_id' => $token->id,
            'client_account_id' => $clientAccount->id,
            'vk_account_id' => $clientAccount->vk_account_id,
            'expires_at' => $token->expires_at
        ]);

        return $token->access_token;
    }

    private function refreshTokenForAccount(VkAdsAccount $account): string
    {
        $account->tokens()->update(['is_active' => false]);
        return $this->createTokenForAccount($account);
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

}
