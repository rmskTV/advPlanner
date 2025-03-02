<?php

namespace Modules\BroadcastingDayTemplates\Repositories;

use App\RedisCacheService;
use App\Repository;
use Modules\BroadcastingDayTemplates\app\Models\BroadcastingDayTemplate;

class BroadcastingDayTemplateRepository extends Repository
{
    public function __construct(BroadcastingDayTemplate $model, RedisCacheService $cacheService)
    {
        parent::__construct($model, 'broadcastingDayTemplate', $cacheService, 10);
    }

}
