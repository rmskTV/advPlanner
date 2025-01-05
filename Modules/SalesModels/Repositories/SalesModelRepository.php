<?php

namespace Modules\SalesModels\Repositories;

use App\RedisCacheService;
use App\Repository;
use Modules\SalesModels\app\Models\SalesModel;

class SalesModelRepository extends Repository
{
    public function __construct(SalesModel $model, RedisCacheService $cacheService)
    {
        parent::__construct($model, 'salesModel', $cacheService, 10);
    }
}
