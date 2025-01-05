<?php

namespace Modules\MediaProducts\Repositories;

use App\RedisCacheService;
use App\Repository;
use Modules\MediaProducts\app\Models\MediaProduct;

class MediaProductRepository extends Repository
{
    public function __construct(MediaProduct $model, RedisCacheService $cacheService)
    {
        parent::__construct($model, 'mediaProduct', $cacheService, 10);
    }
}
