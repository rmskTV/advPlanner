<?php

namespace Modules\EnterpriseData\app\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\CounterpartyGroup;
use Modules\Accounting\app\Models\Currency;
use Modules\Accounting\app\Models\CustomerOrder;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Accounting\app\Models\OrderPaymentStatus;
use Modules\Accounting\app\Models\OrderShipmentStatus;
use Modules\Accounting\app\Models\Organization;
use Modules\Accounting\app\Models\Payment;
use Modules\Accounting\app\Models\Product;
use Modules\Accounting\app\Models\ProductGroup;
use Modules\Accounting\app\Models\Sale;
use Modules\Accounting\app\Models\SystemUser;
use Modules\Accounting\app\Models\UnitOfMeasure;
use Modules\EnterpriseData\app\Exceptions\ExchangeMappingException;
use Modules\EnterpriseData\app\Mappings\CustomerOrderMapping;
use Modules\EnterpriseData\app\Mappings\ObjectDeletionMapping;
use Modules\EnterpriseData\app\Mappings\PaymentMapping;
use Modules\EnterpriseData\app\Mappings\SaleMapping;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Registry\ObjectMappingRegistry;
use Modules\EnterpriseData\app\ValueObjects\ProcessingResult;

class ExchangeDataMapper
{
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

            // Группировка объектов по типам
            $groupedObjects = $this->groupObjectsByType($objects1C);

            foreach ($groupedObjects as $objectType => $objects) {
                try {
                    // СПЕЦИАЛЬНАЯ ОБРАБОТКА для объектов удаления
                    if ($objectType === 'УдалениеОбъекта') {
                        $deletionResult = $this->processDeletions($objects, $connector, $isDryRun);
                        $deletedIds = array_merge($deletedIds, $deletionResult['deleted_ids']);
                        $errors = array_merge($errors, $deletionResult['errors']);
                        $processedCount += count($objects);

                        continue;
                    }

                    // Обычная обработка объектов
                    if (! $this->mappingRegistry->hasMapping($objectType)) {
                        $skippedCount += count($objects);
                        $this->recordUnmappedObject($connector, $objectType, $objects, $isDryRun);

                        continue;
                    }

                    $result = $this->processObjectGroup($objectType, $objects, $connector, $isDryRun);

                    $processedCount += $result->processedCount;
                    $createdIds = array_merge($createdIds, $result->createdIds);
                    $updatedIds = array_merge($updatedIds, $result->updatedIds);
                    $deletedIds = array_merge($deletedIds, $result->deletedIds);
                    $errors = array_merge($errors, $result->errors);

                } catch (\Exception $e) {
                    $errors[] = "Error processing {$objectType}: ".$e->getMessage();
                    Log::error('Object processing failed', [
                        'object_type' => $objectType,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Остальная логика остается без изменений...

            return new ProcessingResult(
                empty($errors),
                $processedCount,
                $createdIds,
                $updatedIds,
                $deletedIds,
                $errors
            );

        } catch (\Exception $e) {
            Log::error('Failed to process incoming objects', [
                'connector_id' => $connector->id,
                'error' => $e->getMessage(),
            ]);

            throw new ExchangeMappingException('Failed to process incoming objects: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Обработка объектов удаления
     */
    /**
     * Обработка объектов удаления
     */
    private function processDeletions(array $deletionObjects, ExchangeFtpConnector $connector, bool $isDryRun): array
    {
        $deletedIds = [];
        $errors = [];

        $deletionMapping = new ObjectDeletionMapping($this->mappingRegistry);

        foreach ($deletionObjects as $deletionObject) {
            try {
                if ($isDryRun) {
                    Log::info('DRY RUN: Would process deletion', [
                        'deletion_object' => $deletionObject,
                    ]);

                    continue;
                }

                $success = $deletionMapping->processDeletion($deletionObject);

                if ($success) {
                    $deletedIds[] = 'deletion_processed_'.time(); // Уникальный ID для статистики
                }
                // НЕ добавляем в ошибки если success = false

            } catch (\Exception $e) {
                // Только реальные исключения считаем ошибками
                $errors[] = 'Error processing deletion: '.$e->getMessage();
                Log::error('Deletion processing error', [
                    'error' => $e->getMessage(),
                    'deletion_object' => $deletionObject,
                ]);
            }
        }

        Log::info('Deletions processing summary', [
            'total_deletion_objects' => count($deletionObjects),
            'successful_deletions' => count($deletedIds),
            'errors' => count($errors),
        ]);

        return [
            'deleted_ids' => $deletedIds,
            'errors' => $errors,
        ];
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

                    $processedCount++;

                    continue;
                }

                // Определение операции и выполнение updateOrCreate
                $result = $this->saveOrUpdateModel($laravelModel, $sanitizedObject, $objectType);

                if ($result['created']) {
                    $createdIds[] = $result['model']->id;
                } else {
                    $updatedIds[] = $result['model']->id;
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
            $model->save();
            $wasCreated = true;
            $savedModel = $model;
        } else {
            $savedModel = $modelClass::updateOrCreate(
                $searchKeys,
                $model->getAttributes()
            );
            $wasCreated = $savedModel->wasRecentlyCreated;
        }

        // Обработка табличных частей для документов
        if ($model instanceof CustomerOrder) {
            $mapping = $this->mappingRegistry->getMapping($objectType);
            if ($mapping instanceof CustomerOrderMapping) {
                $mapping->processTabularSections($savedModel, $object1C);
            }
        } elseif ($model instanceof Sale) {
            $mapping = $this->mappingRegistry->getMapping($objectType);
            if ($mapping instanceof SaleMapping) {
                $mapping->processTabularSections($savedModel, $object1C);
            }
        } elseif ($model instanceof Payment) {
            $mapping = $this->mappingRegistry->getMapping($objectType);
            if ($mapping instanceof PaymentMapping) {
                $mapping->processTabularSections($savedModel, $object1C);
            }
        }

        ObjectChangeLog::log1CChange($modelClass, array_values($searchKeys)[0], $savedModel->id);

        return ['model' => $savedModel, 'created' => $wasCreated];
    }

    /**
     * Получение ключей для поиска существующей записи
     */
    private function getSearchKeys(Model $model, array $object1C): array
    {
        // Приоритет 1: GUID 1С
        if (! empty($model->guid_1c)) {
            return ['guid_1c' => $model->guid_1c];
        }

        $searchKeys = [];

        switch (true) {
            case $model instanceof Organization:
            case $model instanceof Counterparty:
                if (! empty($model->inn)) {
                    $searchKeys['inn'] = $model->inn;
                } elseif (! empty($model->name)) {
                    $searchKeys['name'] = $model->name;
                }
                break;

            case $model instanceof Contract:
            case $model instanceof CustomerOrder:
            case $model instanceof Sale:
                if (! empty($model->number) && ! empty($model->date)) {
                    $searchKeys['number'] = $model->number;
                    $searchKeys['date'] = $model->date->format($model instanceof Contract ? 'Y-m-d' : 'Y-m-d H:i:s');
                } elseif (! empty($model->number)) {
                    $searchKeys['number'] = $model->number;
                }
                break;

            case $model instanceof CounterpartyGroup:
            case $model instanceof SystemUser:
                if (! empty($model->name)) {
                    $searchKeys['name'] = $model->name;
                }
                break;

            case $model instanceof Currency:
            case $model instanceof UnitOfMeasure:
            case $model instanceof ProductGroup:
            case $model instanceof Product:
                if (! empty($model->code)) {
                    $searchKeys['code'] = $model->code;
                } elseif (! empty($model->name)) {
                    $searchKeys['name'] = $model->name;
                }
                break;

            case $model instanceof OrderPaymentStatus:
            case $model instanceof OrderShipmentStatus:
                if (! empty($model->order_guid_1c)) {
                    $searchKeys['order_guid_1c'] = $model->order_guid_1c;
                }
                break;
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
        // Отправка объектов предусмотрена,
        // но будет реализована позже
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
}
