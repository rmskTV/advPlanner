<?php

namespace Modules\BroadcastingDayTemplates\Repositories;

use App\RedisCacheService;
use App\Repository;
use Modules\BroadcastingDayTemplates\app\Models\BroadcastingDayTemplateSlot;

class BroadcastingDayTemplateSlotRepository extends Repository
{
    public function __construct(BroadcastingDayTemplateSlot $model, RedisCacheService $cacheService)
    {
        parent::__construct($model, 'broadcastingDayTemplateSlot', $cacheService, 1000);
    }
}
