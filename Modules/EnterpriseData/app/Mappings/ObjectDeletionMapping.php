<?php

namespace Modules\EnterpriseData\app\Mappings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\CustomerOrder;
use Modules\Accounting\app\Models\Organization;
use Modules\Accounting\app\Models\Product;
use Modules\Accounting\app\Models\Sale;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\Registry\ObjectMappingRegistry;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class ObjectDeletionMapping extends ObjectMapping
{
    private ObjectMappingRegistry $mappingRegistry;

    public function __construct(ObjectMappingRegistry $mappingRegistry)
    {
        $this->mappingRegistry = $mappingRegistry;
    }

    public function getObjectType(): string
    {
        return 'УдалениеОбъекта';
    }

    public function getModelClass(): string
    {
        // Этот маппинг не создает модель, а обрабатывает удаления
        return Model::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        // Этот метод не должен вызываться для удалений
        throw new \RuntimeException('ObjectDeletionMapping should not create models');
    }

    public function mapTo1C(Model $laravelModel): array
    {
        // Генерация объекта удаления для отправки в 1С
        return [
            'type' => 'УдалениеОбъекта',
            'ref' => null,
            'properties' => [
                'СсылкаНаОбъект' => [
                    'СсылкаНаОбъект' => [
                        $this->getObjectTypeKey($laravelModel) => $laravelModel->guid_1c,
                    ],
                ],
            ],
            'tabular_sections' => [],
        ];
    }

    public function validateStructure(array $object1C): ValidationResult
    {
        $properties = $object1C['properties'] ?? [];
        $linkToObject = $properties['СсылкаНаОбъект']['СсылкаНаОбъект'] ?? [];

        if (empty($linkToObject)) {
            return ValidationResult::failure(['Missing object reference for deletion']);
        }

        return ValidationResult::success();
    }

    /**
     * Обработка удаления объекта
     */
    public function processDeletion(array $object1C): bool
    {
        try {
            $properties = $object1C['properties'] ?? [];
            $linkToObject = $properties['СсылкаНаОбъект']['СсылкаНаОбъект'] ?? [];

            Log::info('Processing object deletion', [
                'link_to_object' => $linkToObject,
            ]);

            if (empty($linkToObject)) {
                Log::warning('No object reference found for deletion');

                return false;
            }

            $deletedCount = 0;

            // Обрабатываем каждую ссылку в объекте удаления
            foreach ($linkToObject as $objectTypeKey => $objectGuid) {
                $objectType = $this->parseObjectTypeFromKey($objectTypeKey);

                if (! $objectType) {
                    Log::warning('Cannot determine object type from key', [
                        'object_type_key' => $objectTypeKey,
                    ]);

                    continue;
                }

                Log::info('Processing deletion for object type', [
                    'object_type' => $objectType,
                    'object_guid' => $objectGuid,
                    'object_type_key' => $objectTypeKey,
                ]);

                // Получаем маппинг для этого типа объекта
                $mapping = $this->mappingRegistry->getMapping($objectType);
                if (! $mapping) {
                    Log::warning('No mapping found for object type', [
                        'object_type' => $objectType,
                    ]);

                    continue;
                }

                // Помечаем объект как удаленный
                $modelClass = $mapping->getModelClass();
                $deletedRecords = $modelClass::where('guid_1c', $objectGuid)
                    ->update(['deletion_mark' => true, 'last_sync_at' => now()]);

                if ($deletedRecords > 0) {
                    $deletedCount += $deletedRecords;
                    Log::info('Marked object as deleted', [
                        'object_type' => $objectType,
                        'object_guid' => $objectGuid,
                        'deleted_records' => $deletedRecords,
                    ]);
                } else {
                    Log::warning('Object not found for deletion', [
                        'object_type' => $objectType,
                        'object_guid' => $objectGuid,
                    ]);
                }
            }

            Log::info('Deletion processing completed', [
                'total_deleted' => $deletedCount,
            ]);

            return $deletedCount > 0;

        } catch (\Exception $e) {
            Log::error('Failed to process object deletion', [
                'error' => $e->getMessage(),
                'object_data' => $object1C,
            ]);

            return false;
        }
    }

    /**
     * Парсинг типа объекта из ключа ссылки
     */
    private function parseObjectTypeFromKey(string $objectTypeKey): ?string
    {
        // Маппинг ключей ссылок на типы объектов
        $keyToTypeMap = [
            'ПлатежноеПоручениеСсылка' => 'Документ.ПлатежноеПоручение',
            'ОрганизацияСсылка' => 'Справочник.Организации',
            'КонтрагентСсылка' => 'Справочник.Контрагенты',
            'ДоговорСсылка' => 'Справочник.Договоры',
            'НоменклатураСсылка' => 'Справочник.Номенклатура',
            'ЗаказКлиентаСсылка' => 'Документ.ЗаказКлиента',
            'РеализацияТоваровУслугСсылка' => 'Документ.РеализацияТоваровУслуг',
            // Добавьте другие типы по мере необходимости
        ];

        return $keyToTypeMap[$objectTypeKey] ?? null;
    }

    /**
     * Получение ключа типа объекта для генерации ссылки
     */
    private function getObjectTypeKey(Model $model): string
    {
        $modelClass = get_class($model);

        return match ($modelClass) {
            Organization::class => 'ОрганизацияСсылка',
            Counterparty::class => 'КонтрагентСсылка',
            Contract::class => 'ДоговорСсылка',
            Product::class => 'НоменклатураСсылка',
            CustomerOrder::class => 'ЗаказКлиентаСсылка',
            Sale::class => 'РеализацияТоваровУслугСсылка',
            default => 'ОбъектСсылка'
        };
    }
}
