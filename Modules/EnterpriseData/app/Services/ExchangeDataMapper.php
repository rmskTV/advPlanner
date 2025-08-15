<?php

namespace Modules\EnterpriseData\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\EnterpriseData\app\Exceptions\ExchangeMappingException;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Registry\ObjectMappingRegistry;
use Modules\EnterpriseData\app\ValueObjects\ProcessingResult;

class ExchangeDataMapper
{
    private const MAX_OBJECTS_PER_BATCH = 10000;

    private const ALLOWED_OBJECT_TYPES = [
        'Документ.*',
        'Справочник.*',
        'РегистрСведений.*',
        'РегистрНакопления.*',
        'Константа.*',
    ];

    public function __construct(
        private readonly ObjectMappingRegistry $mappingRegistry,
        private readonly ExchangeDataSanitizer $sanitizer
    ) {}

    public function processIncomingObjects(array $objects1C, ExchangeFtpConnector $connector): ProcessingResult
    {
        try {
            $isDryRun = app()->runningInConsole() &&
                in_array('--dry-run', $_SERVER['argv'] ?? []);

            Log::info('Starting to process incoming objects', [
                'connector_id' => $connector->id,
                'objects_count' => count($objects1C),
                'dry_run' => $isDryRun,
            ]);

            $processedCount = 0;
            $createdIds = [];
            $updatedIds = [];
            $deletedIds = [];
            $errors = [];
            $skippedCount = 0;
            $skippedTypes = [];

            // Группировка объектов по типам для оптимизации
            $groupedObjects = $this->groupObjectsByType($objects1C);

            Log::info('Grouped objects by type', [
                'types_count' => count($groupedObjects),
                'types' => array_keys($groupedObjects),
                'objects_per_type' => array_map('count', $groupedObjects),
            ]);

            foreach ($groupedObjects as $objectType => $objects) {
                try {
                    Log::info('Processing object type', [
                        'object_type' => $objectType,
                        'objects_count' => count($objects),
                    ]);

                    // Проверяем, есть ли маппинг для этого типа объекта
                    if (! $this->mappingRegistry->hasMapping($objectType)) {
                        $skippedTypes[$objectType] = 'No mapping available';
                        $skippedCount += count($objects);

                        $this->recordUnmappedObject($connector, $objectType, $objects, $isDryRun);

                        Log::debug('No mapping found for object type (skipping)', [
                            'object_type' => $objectType,
                            'objects_count' => count($objects),
                        ]);

                        continue;
                    }

                    // Обрабатываем только те типы, для которых есть маппинг
                    $result = $this->processObjectGroup($objectType, $objects, $connector, $isDryRun);

                    $processedCount += $result->processedCount;
                    $createdIds = array_merge($createdIds, $result->createdIds);
                    $updatedIds = array_merge($updatedIds, $result->updatedIds);
                    $deletedIds = array_merge($deletedIds, $result->deletedIds);
                    $errors = array_merge($errors, $result->errors);

                    Log::info('Processed object type', [
                        'object_type' => $objectType,
                        'processed_count' => $result->processedCount,
                        'created_count' => count($result->createdIds),
                        'updated_count' => count($result->updatedIds),
                        'deleted_count' => count($result->deletedIds),
                        'errors_count' => count($result->errors),
                    ]);

                } catch (\Exception $e) {
                    $errors[] = "Error processing {$objectType}: ".$e->getMessage();
                    Log::error('Object processing failed', [
                        'object_type' => $objectType,
                        'error' => $e->getMessage(),
                        'connector' => $connector->id,
                    ]);
                }
            }

            // Логируем информацию о пропущенных типах
            if (! empty($skippedTypes)) {
                Log::info('Skipped object types without mappings', [
                    'connector_id' => $connector->id,
                    'skipped_types' => array_keys($skippedTypes),
                    'skipped_objects_count' => $skippedCount,
                    'total_skipped_types' => count($skippedTypes),
                ]);
            }

            $finalResult = new ProcessingResult(
                empty($errors),
                $processedCount,
                $createdIds,
                $updatedIds,
                $deletedIds,
                $errors
            );

            Log::info('Finished processing incoming objects', [
                'connector_id' => $connector->id,
                'total_objects_in_message' => count($objects1C),
                'total_processed' => $processedCount,
                'total_skipped' => $skippedCount,
                'total_created' => count($createdIds),
                'total_updated' => count($updatedIds),
                'total_deleted' => count($deletedIds),
                'total_errors' => count($errors),
                'success' => $finalResult->success,
                'dry_run' => $isDryRun,
            ]);

            return $finalResult;

        } catch (\Exception $e) {
            Log::error('Failed to process incoming objects', [
                'connector_id' => $connector->id,
                'error' => $e->getMessage(),
                'objects_count' => count($objects1C),
            ]);

            throw new ExchangeMappingException('Failed to process incoming objects: '.$e->getMessage(), 0, $e);
        }
    }

    private function processObjectGroup(string $objectType, array $objects, ExchangeFtpConnector $connector, bool $isDryRun = false): ProcessingResult
    {
        $mapping = $this->mappingRegistry->getMapping($objectType);
        if (! $mapping) {
            return new ProcessingResult(true, 0, [], [], [], ["No mapping for {$objectType}"]);
        }

        $processedCount = 0;
        $createdIds = [];
        $updatedIds = [];
        $deletedIds = [];
        $errors = [];

        foreach ($objects as $object1C) {
            try {
                // Санитизация входящего объекта
                $sanitizedObject = $this->sanitizer->sanitizeIncomingObject($object1C);

                // Валидация структуры объекта
                $validation = $mapping->validateStructure($sanitizedObject);
                if (! $validation->isValid()) {
                    $errors[] = "Invalid object structure for {$objectType}: ".implode(', ', $validation->getErrors());

                    continue;
                }

                // Маппинг в модель Laravel
                $laravelModel = $mapping->mapFrom1C($sanitizedObject);

                if ($isDryRun) {
                    Log::info('DRY RUN: Would process object', [
                        'object_type' => $objectType,
                        'object_ref' => $sanitizedObject['ref'] ?? 'not set',
                        'model_class' => get_class($laravelModel),
                    ]);

                    $processedCount++;

                    continue;
                }

                // Определение операции и выполнение updateOrCreate
                $result = $this->saveOrUpdateModel($laravelModel, $sanitizedObject, $objectType);

                if ($result['created']) {
                    $createdIds[] = $result['model']->id;
                    Log::debug('Created object', [
                        'object_type' => $objectType,
                        'model_id' => $result['model']->id,
                        'guid_1c' => $result['model']->guid_1c ?? 'not set',
                    ]);
                } else {
                    $updatedIds[] = $result['model']->id;
                    Log::debug('Updated object', [
                        'object_type' => $objectType,
                        'model_id' => $result['model']->id,
                        'guid_1c' => $result['model']->guid_1c ?? 'not set',
                    ]);
                }

                $processedCount++;

            } catch (\Exception $e) {
                $objectRef = $object1C['ref'] ?? 'unknown';
                $errors[] = "Error processing object {$objectRef}: ".$e->getMessage();

                Log::error('Object processing error', [
                    'object_type' => $objectType,
                    'object_ref' => $objectRef,
                    'error' => $e->getMessage(),
                    'connector_id' => $connector->id,
                ]);
            }
        }

        return new ProcessingResult(
            empty($errors),
            $processedCount,
            $createdIds,
            $updatedIds,
            $deletedIds,
            $errors
        );
    }

    /**
     * Сохранение или обновление модели с использованием updateOrCreate
     */
    private function saveOrUpdateModel(Model $model, array $object1C, string $objectType): array
    {
        $modelClass = get_class($model);

        // Определяем ключи для поиска существующей записи
        $searchKeys = $this->getSearchKeys($model, $object1C);

        if (empty($searchKeys)) {
            // Если нет ключей для поиска, просто создаем
            $model->save();

            return ['model' => $model, 'created' => true];
        }

        Log::debug('Using updateOrCreate', [
            'object_type' => $objectType,
            'model_class' => $modelClass,
            'search_keys' => $searchKeys,
            'update_data_keys' => array_keys($model->getAttributes()),
        ]);

        // Используем updateOrCreate
        $savedModel = $modelClass::updateOrCreate(
            $searchKeys,
            $model->getAttributes()
        );

        $wasRecentlyCreated = $savedModel->wasRecentlyCreated;

        Log::info('Model saved with updateOrCreate', [
            'object_type' => $objectType,
            'model_id' => $savedModel->id,
            'was_created' => $wasRecentlyCreated,
            'guid_1c' => $savedModel->guid_1c ?? 'not set',
        ]);

        return ['model' => $savedModel, 'created' => $wasRecentlyCreated];
    }

    /**
     * Получение ключей для поиска существующей записи
     */
    private function getSearchKeys(Model $model, array $object1C): array
    {
        $searchKeys = [];

        // Приоритет 1: GUID 1С
        if (!empty($model->guid_1c)) {
            $searchKeys['guid_1c'] = $model->guid_1c;
            return $searchKeys;
        }

        // Приоритет 2: Уникальные поля в зависимости от типа модели
        if ($model instanceof \Modules\Accounting\app\Models\Organization) {
            if (!empty($model->inn)) {
                $searchKeys['inn'] = $model->inn;
            } elseif (!empty($model->name)) {
                $searchKeys['name'] = $model->name;
            }
        } elseif ($model instanceof \Modules\Accounting\app\Models\Contract) {
            if (!empty($model->number) && !empty($model->date)) {
                $searchKeys['number'] = $model->number;
                $searchKeys['date'] = $model->date->format('Y-m-d');
            } elseif (!empty($model->number)) {
                $searchKeys['number'] = $model->number;
            }
        } elseif ($model instanceof \Modules\Accounting\app\Models\CounterpartyGroup) {
            if (!empty($model->name)) {
                $searchKeys['name'] = $model->name;
            }
        } elseif ($model instanceof \Modules\Accounting\app\Models\Counterparty) {
            if (!empty($model->inn)) {
                $searchKeys['inn'] = $model->inn;
            } elseif (!empty($model->name)) {
                $searchKeys['name'] = $model->name;
            }
        } elseif ($model instanceof \App\Models\Individual) {
            if (!empty($model->inn)) {
                $searchKeys['inn'] = $model->inn;
            } elseif (!empty($model->full_name) && !empty($model->birth_date)) {
                $searchKeys['full_name'] = $model->full_name;
                $searchKeys['birth_date'] = $model->birth_date->format('Y-m-d');
            } elseif (!empty($model->full_name)) {
                $searchKeys['full_name'] = $model->full_name;
            }
        } elseif ($model instanceof \Modules\Accounting\app\Models\Currency) {
            if (!empty($model->code)) {
                $searchKeys['code'] = $model->code;
            } elseif (!empty($model->name)) {
                $searchKeys['name'] = $model->name;
            }
        } elseif ($model instanceof \Modules\Accounting\app\Models\SystemUser) {
            if (!empty($model->name)) {
                $searchKeys['name'] = $model->name;
            }
        } elseif ($model instanceof \Modules\Accounting\app\Models\UnitOfMeasure) {
            if (!empty($model->code)) {
                $searchKeys['code'] = $model->code;
            } elseif (!empty($model->name)) {
                $searchKeys['name'] = $model->name;
            }
        } elseif ($model instanceof \Modules\Accounting\app\Models\ProductGroup) {
            if (!empty($model->code)) {
                $searchKeys['code'] = $model->code;
            } elseif (!empty($model->name)) {
                $searchKeys['name'] = $model->name;
            }
        } elseif ($model instanceof \Modules\Accounting\app\Models\Product) {
            if (!empty($model->code)) {
                $searchKeys['code'] = $model->code;
            } elseif (!empty($model->name)) {
                $searchKeys['name'] = $model->name;
            }
        }

        return $searchKeys;
    }

    /**
     * Запись информации о немаппированном объекте
     */
    private function recordUnmappedObject(
        ExchangeFtpConnector $connector,
        string $objectType,
        array $objects,
        bool $isDryRun = false
    ): void {
        try {
            if ($isDryRun) {
                Log::info('DRY RUN: Would record unmapped object', [
                    'connector_id' => $connector->id,
                    'object_type' => $objectType,
                    'objects_count' => count($objects),
                ]);

                return;
            }

            // Берем первый объект как пример
            $sampleObject = $objects[0] ?? [];

            // Очищаем пример от слишком больших данных
            $cleanSample = [
                'type' => $sampleObject['type'] ?? $objectType,
                'ref' => $sampleObject['ref'] ?? null,
                'properties' => array_slice($sampleObject['properties'] ?? [], 0, 10, true),
                'tabular_sections' => array_map(
                    fn ($section) => array_slice($section, 0, 2),
                    array_slice($sampleObject['tabular_sections'] ?? [], 0, 3, true)
                ),
                'properties_count' => count($sampleObject['properties'] ?? []),
                'tabular_sections_count' => count($sampleObject['tabular_sections'] ?? []),
            ];

            \Modules\EnterpriseData\app\Models\ExchangeUnmappedObject::recordUnmappedObject(
                $connector->id,
                $objectType,
                $cleanSample
            );

            Log::info('Recorded unmapped object', [
                'connector_id' => $connector->id,
                'object_type' => $objectType,
                'objects_count' => count($objects),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to record unmapped object', [
                'connector_id' => $connector->id,
                'object_type' => $objectType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function mapFromLaravelTo1C(Collection $laravelObjects, string $objectType): array
    {
        try {
            $mapping = $this->mappingRegistry->getMapping($objectType);
            if (! $mapping) {
                throw new ExchangeMappingException("No mapping found for object type: {$objectType}");
            }

            $objects1C = [];
            foreach ($laravelObjects as $laravelObject) {
                try {
                    // Валидация модели перед маппингом
                    $this->validateLaravelModel($laravelObject, $mapping->getModelClass());

                    $object1C = $mapping->mapTo1C($laravelObject);

                    // Санитизация данных перед отправкой
                    $object1C = $this->sanitizer->sanitizeOutgoingObject($object1C);

                    $objects1C[] = $object1C;

                } catch (\Exception $e) {
                    Log::error('Model mapping failed', [
                        'model_id' => $laravelObject->id ?? 'unknown',
                        'object_type' => $objectType,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }

            return $objects1C;

        } catch (\Exception $e) {
            throw new ExchangeMappingException('Failed to map Laravel objects to 1C: '.$e->getMessage(), 0, $e);
        }
    }

    public function getObjectsForSending(ExchangeFtpConnector $connector): Collection
    {
        // Реализация зависит от вашей бизнес-логики
        // Например, поиск измененных объектов с определенной даты
        return collect([]);
    }

    private function groupObjectsByType(array $objects1C): array
    {
        $grouped = [];

        foreach ($objects1C as $object) {
            $type = $object['type'] ?? 'Unknown';
            if (! isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $object;
        }

        return $grouped;
    }

    private function isAllowedObjectType(string $objectType): bool
    {
        foreach (self::ALLOWED_OBJECT_TYPES as $allowedPattern) {
            if (fnmatch($allowedPattern, $objectType)) {
                return true;
            }
        }

        return false;
    }

    private function validateLaravelModel(Model $model, string $expectedClass): void
    {
        if (! $model instanceof $expectedClass) {
            throw new ExchangeMappingException(
                "Model is not instance of expected class. Expected: {$expectedClass}, Got: ".get_class($model)
            );
        }

        // Дополнительная валидация модели
        if (! $model->exists && ! $model->isDirty()) {
            throw new ExchangeMappingException('Model has no data to process');
        }
    }

    private function determineOperation(array $object1C, Model $laravelModel): string
    {
        // Логика определения операции на основе данных объекта
        if (isset($object1C['properties']['ПометкаУдаления']) && $object1C['properties']['ПометкаУдаления'] === true) {
            return 'delete';
        }

        // Проверка существования объекта
        $existingModel = $this->findExistingModel($laravelModel, $object1C);

        return $existingModel ? 'update' : 'create';
    }

    private function findExistingModel(Model $laravelModel, array $object1C): ?Model
    {
        // Поиск существующей модели по GUID или другим уникальным полям
        $modelClass = get_class($laravelModel);

        // Поиск по GUID 1С
        if (isset($object1C['ref'])) {
            return $modelClass::where('guid_1c', $object1C['ref'])->first();
        }

        // Поиск по другим уникальным полям (зависит от маппинга)
        return null;
    }
}
