<?php

namespace Modules\VkAds\app\Services;

use App\RedisCacheService;
use Carbon\Carbon;
use Modules\VkAds\app\Models\VkAdsAccount;

class VkAdsRateLimiter
{
    private RedisCacheService $cache;

    private int $requestsPerMinute;

    private int $requestsPerHour;

    private int $burstLimit;

    public function __construct(RedisCacheService $cache)
    {
        $this->cache = $cache;
        $this->requestsPerMinute = config('vkads.rate_limits.requests_per_minute', 100);
        $this->requestsPerHour = config('vkads.rate_limits.requests_per_hour', 5000);
        $this->burstLimit = config('vkads.rate_limits.burst_limit', 10);
    }

    /**
     * Проверить, можно ли выполнить запрос
     */
    public function canMakeRequest(VkAdsAccount $account, string $endpoint = 'default'): bool
    {
        $minuteKey = $this->getMinuteKey($account, $endpoint);
        $hourKey = $this->getHourKey($account, $endpoint);
        $burstKey = $this->getBurstKey($account, $endpoint);

        $minuteCount = (int) $this->cache->get($minuteKey) ?: 0;
        $hourCount = (int) $this->cache->get($hourKey) ?: 0;
        $burstCount = (int) $this->cache->get($burstKey) ?: 0;

        // Проверяем все лимиты
        if ($minuteCount >= $this->requestsPerMinute) {
            return false;
        }

        if ($hourCount >= $this->requestsPerHour) {
            return false;
        }

        if ($burstCount >= $this->burstLimit) {
            return false;
        }

        return true;
    }

    /**
     * Записать выполненный запрос
     */
    public function recordRequest(VkAdsAccount $account, string $endpoint = 'default'): void
    {
        $now = Carbon::now();

        $minuteKey = $this->getMinuteKey($account, $endpoint);
        $hourKey = $this->getHourKey($account, $endpoint);
        $burstKey = $this->getBurstKey($account, $endpoint);

        // Увеличиваем счетчики
        $this->incrementCounter($minuteKey, 60); // TTL 1 минута
        $this->incrementCounter($hourKey, 3600); // TTL 1 час
        $this->incrementCounter($burstKey, 1); // TTL 1 секунда
    }

    /**
     * Получить время ожидания до следующего запроса
     */
    public function getWaitTime(VkAdsAccount $account, string $endpoint = 'default'): int
    {
        $minuteKey = $this->getMinuteKey($account, $endpoint);
        $minuteCount = (int) $this->cache->get($minuteKey) ?: 0;

        if ($minuteCount >= $this->requestsPerMinute) {
            return 60; // Ждем минуту
        }

        $burstKey = $this->getBurstKey($account, $endpoint);
        $burstCount = (int) $this->cache->get($burstKey) ?: 0;

        if ($burstCount >= $this->burstLimit) {
            return 1; // Ждем секунду
        }

        return 0;
    }

    /**
     * Сбросить лимиты (для тестирования)
     */
    public function resetLimits(VkAdsAccount $account): void
    {
        $pattern = "vk_ads_rate_limit:account_{$account->id}:*";
        $this->cache->forgetBySubstring("account_{$account->id}");
    }

    private function getMinuteKey(VkAdsAccount $account, string $endpoint): string
    {
        $minute = Carbon::now()->format('Y-m-d-H-i');

        return "vk_ads_rate_limit:account_{$account->id}:endpoint_{$endpoint}:minute_{$minute}";
    }

    private function getHourKey(VkAdsAccount $account, string $endpoint): string
    {
        $hour = Carbon::now()->format('Y-m-d-H');

        return "vk_ads_rate_limit:account_{$account->id}:endpoint_{$endpoint}:hour_{$hour}";
    }

    private function getBurstKey(VkAdsAccount $account, string $endpoint): string
    {
        $second = Carbon::now()->format('Y-m-d-H-i-s');

        return "vk_ads_rate_limit:account_{$account->id}:endpoint_{$endpoint}:burst_{$second}";
    }

    private function incrementCounter(string $key, int $ttl): void
    {
        if ($this->cache->exists($key)) {
            $current = (int) $this->cache->get($key);
            $this->cache->set($key, $current + 1, [], $ttl);
        } else {
            $this->cache->set($key, 1, [], $ttl);
        }
    }
}
