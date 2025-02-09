<?php

namespace Modules\AdvBlocks\Repositories;

use App\RedisCacheService;
use App\Repository;
use Modules\AdvBlocks\app\Models\AdvBlock;

class AdvBlockRepository extends Repository
{
    public function __construct(AdvBlock $model, RedisCacheService $cacheService)
    {
        parent::__construct($model, 'advBlock', $cacheService, 10);
    }
}
