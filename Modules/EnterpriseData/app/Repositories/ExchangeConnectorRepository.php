<?php

namespace Modules\EnterpriseData\app\Repositories;

use App\RedisCacheService;
use App\Repository;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;

class ExchangeConnectorRepository extends Repository
{
    public function __construct(ExchangeFtpConnector $model, RedisCacheService $cacheService)
    {
        parent::__construct($model, 'exchangeConnector', $cacheService, 10);
    }
}
