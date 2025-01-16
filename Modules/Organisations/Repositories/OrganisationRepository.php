<?php

namespace Modules\Organisations\Repositories;

use App\RedisCacheService;
use App\Repository;
use Modules\Organisations\app\Models\Organisation;

class OrganisationRepository extends Repository
{
    public function __construct(Organisation $model, RedisCacheService $cacheService)
    {
        parent::__construct($model, 'organisation', $cacheService, 10);
    }
}
