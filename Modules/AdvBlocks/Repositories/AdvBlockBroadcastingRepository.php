<?php

namespace Modules\AdvBlocks\Repositories;

use App\RedisCacheService;
use App\Repository;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\AdvBlocks\Models\AdvBlockBroadcasting;

class AdvBlockBroadcastingRepository extends Repository
{
    public function __construct(AdvBlockBroadcasting $model, RedisCacheService $cacheService)
    {
        parent::__construct($model, 'advBlockBroadcasting', $cacheService, 10);
    }

    public function getAll(array $with = [], array $filters = []): LengthAwarePaginator
    {
        $query = $this->model::query()->with($with);

        // Применяем фильтры для Equal-запросов
        foreach ($filters as $key => $value) {
            if ($value !== null && ! in_array($key, ['broadcast_at_from', 'broadcast_at_to'])) {
                $query->where($key, $value);
            }
        }

        // Фильтрация по broadcast_at_from (greater than or equal)
        if (isset($filters['broadcast_at_from']) && $filters['broadcast_at_from'] !== null) {
            $query->where('broadcast_at', '>=', $filters['broadcast_at_from'] . " 00:00:00");
        }

        // Фильтрация по broadcast_at_to (less than or equal)
        if (isset($filters['broadcast_at_to']) && $filters['broadcast_at_to'] !== null) {
            $query->where('broadcast_at', '<=', $filters['broadcast_at_to'] . " 23:59:59");
        }

        return $query->paginate($this->paginationCount);
    }
}
