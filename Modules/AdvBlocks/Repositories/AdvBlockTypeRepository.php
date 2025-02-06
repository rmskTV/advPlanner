<?php

namespace Modules\AdvBlocks\Repositories;

use App\RedisCacheService;
use App\Repository;
use Modules\AdvBlocks\app\Models\AdvBlockType;

class AdvBlockTypeRepository extends Repository
{
    public function __construct(AdvBlockType $model, RedisCacheService $cacheService)
    {
        parent::__construct($model, 'advBlockType', $cacheService, 10);
    }
}
