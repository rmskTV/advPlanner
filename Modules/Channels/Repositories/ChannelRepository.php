<?php

namespace Modules\Channels\Repositories;

use App\RedisCacheService;
use App\Repository;
use Modules\Channels\app\Models\Channel;

class ChannelRepository extends Repository
{
    public function __construct(Channel $model, RedisCacheService $cacheService)
    {
        parent::__construct($model, 'channel', $cacheService, 10);
    }
}
