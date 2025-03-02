<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Class Repository
 *
 * Основной репозиторий для работы с моделью и кэшированием.
 */
class Repository
{
    protected Model $model;

    protected string $prefix;

    protected int $paginationCount;

    protected int $expiration;

    protected RedisCacheService $cacheService;

    /**
     * Конструктор репозитория.
     *
     * @param  Model  $model  Модель Eloquent.
     * @param  string  $prefix  Префикс для кэш-ключей.
     * @param  RedisCacheService  $cacheService  Сервис для работы с Redis кэшем.
     */
    public function __construct(Model $model, string $prefix, RedisCacheService $cacheService, int $paginationCount)
    {
        if (request()->has('per_page')) {
            $this->paginationCount = request()->per_page;
        } else {
            $this->paginationCount = $paginationCount;
        }
        $this->model = $model;
        $this->cacheService = $cacheService;
        $this->prefix = $prefix;
        $this->expiration = $model::cacheExpiried();
    }

    /**
     * Создает новую запись.
     *
     * @param  array<string, int|string|false>  $data  Данные для создания записи.
     * @return Model|null $createdRecord Возвращает созданный объект или null (в случае неуспеха)
     */
    public function create(array $data): ?Model
    {

        $model = $this->model::query()->getModel();
        $createdRecord = $model->create($data);

        if ($createdRecord) {
            $id = $createdRecord->id;
            $createdRecord = $model::query()->find($id);

            if ($createdRecord !== null) {
                $cacheKey = $this->prefix.$id;
                $this->cacheService->set($cacheKey, json_encode($createdRecord));
                $this->cacheService->forgetBySubstring($this->prefix.'_getAll');
                Log::info("Cached created record with ID: $id");
            } else {
                Log::error('Failed to find created record with ID: $id after creation');

                return null;
            }
        } else {
            Log::error('Failed to create record');

            return null;
        }

        return $createdRecord;
    }

    /**
     * Получает запись по ID.
     *
     * @param  int  $id  Идентификатор записи.
     * @return Model|null Возвращает модель или null, если запись не найдена.
     */
    public function getById(int $id, array $with = []): ?Model
    {
        $cacheKey = $this->prefix.$id.'_with-'.implode('-', $with);
        $cachedData = $this->cacheService->get($cacheKey);

        if ($cachedData !== null) {
            return $this->model->newFromBuilder(json_decode($cachedData, true));
        }

        $data = $this->model::query()
            ->with($with)
            ->find($id);

        if ($data !== null) {
            $this->cacheService->set($cacheKey, json_encode($data));
        }

        return $data;
    }

    public function getAll(array $with = [], array $filters = []): LengthAwarePaginator
    {
        $pageNumber = LengthAwarePaginator::resolveCurrentPage();
        $cacheKey = $this->prefix.'_getAll_flt-'.http_build_query($filters, '', '_AND_').'_with-'.implode('-', $with).'_'.$this->paginationCount.'_page'.$pageNumber;

        $cachedData = $this->extractListFromCache($cacheKey);

        if ($cachedData !== null) {
            return $cachedData;
        }

        $data = $this->model::query()
            ->with($with)
            ->where($filters)
            ->paginate($this->paginationCount);

        return $this->storeDataToCacheAndPaginator($cacheKey, $data, [$this->prefix.'_list']);
    }

    /**
     * @param  int  $id  ID удаляемой записи
     */
    public function delete(int $id): int
    {
        $deleted = $this->model::query()->where('id', $id)->delete();

        if ($deleted) {
            $cacheKey = $this->prefix.$id;
            $this->cacheService->delete($cacheKey);
            $this->cacheService->forgetBySubstring($this->prefix.'_getAll');
        } else {
            Log::error("Deleted cache for record with ID: $id");
        }

        return $deleted;
    }

    /**
     * Обновляет запись по ID.
     *
     * @param  int  $id  Идентификатор записи.
     * @param  array<array<int|string>|string>  $data  Данные для обновления записи.
     * @return Model|null Возвращает количество обновленных записей.
     */
    public function update(int $id, array $data): ?Model
    {
        $updated = $this->model::query()->where('id', $id)->update($data);

        if ($updated) {
            $updatedRecord = $this->model::query()->find($id);

            if ($updatedRecord !== null) {
                $cacheKey = $this->prefix.$id;
                $this->cacheService->set($cacheKey, json_encode($updatedRecord));
                Log::info("Updated cache for record with ID: $id");
                $this->cacheService->forgetBySubstring($this->prefix.'_getAll');

                return $updatedRecord;

            } else {
                Log::error("Failed to find updated record with ID: $id");
            }
        } else {
            Log::error("Failed to update record with ID: $id");
        }

        return null;
    }

    private function extractListFromCache(string $cacheKey): ?LengthAwarePaginator
    {
        $cachedData = $this->cacheService->get($cacheKey);
        if ($cachedData !== null) {
            Log::info("Loaded cached data for key: $cacheKey");
            $decodedData = json_decode($cachedData, true);

            return new LengthAwarePaginator(
                $decodedData['items'],
                $decodedData['total'],
                $this->paginationCount,
                $decodedData['pageNumber'],
                ['path' => LengthAwarePaginator::resolveCurrentPath()]
            );
        }

        return null;
    }

    /**
     * @param  string[]|null  $tags
     */
    private function storeDataToCacheAndPaginator(string $cacheKey, LengthAwarePaginator $data, ?array $tags = []): LengthAwarePaginator
    {

        $this->cacheService->set($cacheKey, json_encode([
            'items' => $data->items(),
            'total' => $data->total(),
            'pageNumber' => $data->currentPage(),
        ]), $tags, $this->expiration);

        Log::info("Cached list data for key: $cacheKey");

        return $data;
    }
}
