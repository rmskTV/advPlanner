<?php

namespace Modules\VkAds\app\Services;

use App\RedisCacheService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\VkAds\app\Exceptions\VkAdsApiException;
use Modules\VkAds\app\Exceptions\VkAdsAuthenticationException;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Models\VkAdsToken;

class VkAdsApiService
{
    private string $baseUrl = 'https://ads.vk.com/api/v2/';

    private RedisCacheService $cache;

    public function __construct(RedisCacheService $cache)
    {
        $this->cache = $cache;
    }

    // === АУТЕНТИФИКАЦИЯ ===

    public function authenticate(string $clientId, string $clientSecret): VkAdsToken
    {
        $response = Http::post($this->baseUrl.'oauth2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if (! $response->successful()) {
            throw new VkAdsAuthenticationException('Authentication failed');
        }

        $data = $response->json();

        return VkAdsToken::create([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds($data['expires_in']),
            'scopes' => $data['scope'] ?? [],
            'is_active' => true,
        ]);
    }

    public function refreshToken(VkAdsToken $token): VkAdsToken
    {
        if (! $token->refresh_token) {
            throw new VkAdsAuthenticationException('No refresh token available');
        }

        $response = Http::post($this->baseUrl.'oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refresh_token,
        ]);

        if (! $response->successful()) {
            throw new VkAdsAuthenticationException('Token refresh failed');
        }

        $data = $response->json();

        $token->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $token->refresh_token,
            'expires_at' => now()->addSeconds($data['expires_in']),
        ]);

        return $token;
    }

    public function validateToken(VkAdsToken $token): bool
    {
        try {
            $response = $this->makeAuthenticatedRequest(
                $token->account,
                'accounts.get'
            );

            return ! empty($response);
        } catch (\Exception $e) {
            return false;
        }
    }

    // === БАЗОВЫЕ ЗАПРОСЫ ===

    public function makeRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $url = $this->baseUrl.ltrim($endpoint, '/');

        $response = Http::$method($url, $params);

        $this->logApiCall($endpoint, $params, $response->json());

        return $this->handleApiResponse($response->json());
    }

    public function makeAuthenticatedRequest(VkAdsAccount $account, string $endpoint, array $params = []): array
    {
        $token = $account->getValidToken();

        if (! $token) {
            throw new VkAdsAuthenticationException('No valid token for account');
        }

        if (! $this->checkRateLimit($account)) {
            $this->waitForRateLimit($account);
        }

        $params['access_token'] = $token->access_token;

        $response = $this->makeRequest($endpoint, $params);

        return $response;
    }

    // === ОБРАБОТКА ОШИБОК ===

    public function handleApiResponse(array $response): array
    {
        if (isset($response['error'])) {
            throw new VkAdsApiException(
                $response['error']['error_msg'] ?? 'API Error',
                $response['error']['error_code'] ?? 0
            );
        }

        return $response['response'] ?? $response;
    }

    public function logApiCall(string $endpoint, array $params, array $response): void
    {
        Log::info('VK Ads API Call', [
            'endpoint' => $endpoint,
            'params' => $params,
            'response_status' => isset($response['error']) ? 'error' : 'success',
        ]);
    }

    // === ЛИМИТЫ И THROTTLING ===

    public function checkRateLimit(VkAdsAccount $account): bool
    {
        $key = "vk_ads_rate_limit:{$account->id}";
        $requests = $this->cache->get($key) ?? 0;

        return $requests < 100; // 100 запросов в минуту
    }

    public function waitForRateLimit(VkAdsAccount $account): void
    {
        sleep(60); // Ждем минуту
    }
}
