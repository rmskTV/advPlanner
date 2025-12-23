<?php

namespace Modules\Bitrix24\app\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class Bitrix24Service
{
    protected string $webhookUrl;

    protected int $timeout = 30; // дефолтное значение

    public function __construct()
    {
        $this->webhookUrl = config('bitrix24.webhook.url');
        // Используем значение из конфига если есть, иначе дефолтное
        $this->timeout = config('bitrix24.webhook.timeout', $this->timeout);
    }

    /**
     * @throws ConnectionException
     */
    public function call($method, $params = [])
    {
        return Http::timeout($this->timeout)
            ->post($this->webhookUrl.$method, $params)
            ->json();
    }

    public function batch($calls)
    {
        // Пакетные запросы
    }
}
