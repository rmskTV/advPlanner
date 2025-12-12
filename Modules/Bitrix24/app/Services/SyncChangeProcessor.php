<?php

namespace Modules\Bitrix24\app\Services;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Accounting\app\Models\Product;
use Modules\Accounting\app\Models\ProductGroup;
use Modules\Accounting\app\Models\UnitOfMeasure;

class SyncChangeProcessor
{
    protected $b24Service;

    // Разрешенные типы объектов и их обработчики
    protected $allowedTypes = [
        'Modules\Accounting\app\Models\ProductGroup' => 'processProductGroup',
        'Modules\Accounting\app\Models\Product' => 'processProduct',
        'Modules\Accounting\app\Models\Counterparty' => 'processCounterparty',
        'Modules\Accounting\app\Models\ContactPerson' => 'processContactPerson',
        'Modules\Accounting\app\Models\Contract' => 'processContract',
        'Modules\Accounting\app\Models\Organization' => 'processOrganization',
    ];



    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    public function process()
    {
        $changes = ObjectChangeLog::query()
            ->where('status', 'pending')
            ->get();

        foreach ($changes as $change) {
            try {
                // Проверяем направление
                if ($change->source !== '1C') {
                    continue; // пока обрабатываем только изменения из 1С
                }
                else{
                    // Проверяем тип объекта
                    if (!isset($this->allowedTypes[$change->entity_type])) {
                        continue; // пропускаем неподдерживаемые типы
                    }
                    // Вызываем соответствующий обработчик
                    $method = $this->allowedTypes[$change->entity_type];

                    $start = microtime(true);
                    $this->$method($change);
                    $elapsed = microtime(true) - $start;

                    $remaining = 1 - $elapsed;
                    if ($remaining > 0) {
                        usleep((int) round($remaining * 1_000_000)); // usleep в микросекундах
                    }
                }

            } catch (\Exception $e) {
                Log::error("Error processing change {$change->id}: " . $e->getMessage());
                $change->markError($e->getMessage());
            }
        }
    }

    protected function processCounterparty($change)
    {
        $processor = new CRMSyncProcessor($this->b24Service);
        return $processor->processCompany($change);
    }

    protected function processContactPerson($change)
    {
        $processor = new CRMSyncProcessor($this->b24Service);
        return $processor->processContact($change);
    }
    protected function processContract($change): void
    {
        $processor = new ContractSyncProcessor($this->b24Service);
        $processor->processContract($change);
    }
    protected function processProduct($change)
    {
        $product = \Modules\Accounting\app\Models\Product::find($change->local_id);
        if (!$product) {
            throw new \Exception("Product not found: {$change->local_id}");
        }

        // Получаем ID секции
        $sectionId = null;
        if ($product->group_guid_1c) {
            $sectionResult = $this->b24Service->call('crm.productsection.list', [
                'filter' => ['CODE' => $product->group_guid_1c],
                'select' => ['ID']
            ]);
            if (!empty($sectionResult['result'][0])) {
                $sectionId = $sectionResult['result'][0]['ID'];
            }
        }

        // Получаем ID пользовательских свойств
        $propertyIds = $this->getProductPropertyIds();

        // Основные поля товара
        $b24Fields = [
            'NAME' => html_entity_decode($product->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'CODE' => $product->code,
            'DESCRIPTION' => html_entity_decode($product->description, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?? '',
            'SECTION_ID' => $sectionId,
            'PRICE' => 0,
            'CURRENCY_ID' => 'RUB',
            'SORT' => 500,
            'VAT_ID' => $this->mapVatRate($product->vat_rate),
            'VAT_INCLUDED' => 'Y',
            'MEASURE' => $this->getB24MeasureId($product->unit_guid_1c)
        ];

        // Добавляем значения пользовательских свойств
        if (isset($propertyIds['GUID_1C'])) {
            $b24Fields['PROPERTY_' . $propertyIds['GUID_1C']] = $product->guid_1c;
        }
        if (isset($propertyIds['ANALYTICS_GROUP'])) {
            $b24Fields['PROPERTY_' . $propertyIds['ANALYTICS_GROUP']] = $product->analytics_group_name;
        }
        if (isset($propertyIds['ANALYTICS_GROUP_GUID'])) {
            $b24Fields['PROPERTY_' . $propertyIds['ANALYTICS_GROUP_GUID']] = $product->analytics_group_guid_1c;
        }

        // Проверяем существование продукта по GUID
        $existingProduct = $this->findProductByGuid($product->guid_1c, $propertyIds);

        try {
            if ($existingProduct) {
                // Обновляем существующий товар
                $b24Id = $existingProduct;
                $result = $this->b24Service->call('crm.product.update', [
                    'id' => $b24Id,
                    'fields' => $b24Fields
                ]);
            } else {
                // Создаем новый товар
                $result = $this->b24Service->call('crm.product.add', [
                    'fields' => $b24Fields
                ]);
                $b24Id = $result['result'];
            }

            if (!$b24Id) {
                throw new \Exception("Failed to get B24 product ID");
            }

            $change->b24_id = $b24Id;
            $change->markProcessed();

            \Log::info("Processed product", [
                'local_id' => $product->id,
                'guid_1c' => $product->guid_1c,
                'b24_id' => $b24Id,
                'action' => $existingProduct ? 'update' : 'create',
                'fields' => $b24Fields
            ]);

        } catch (\Exception $e) {
            throw new \Exception("Error processing product {$product->id}: " . $e->getMessage());
        }
    }

// Метод для получения ID пользовательских свойств
    protected function getProductPropertyIds()
    {
        static $propertyIds = null;

        if ($propertyIds === null) {
            $properties = $this->b24Service->call('crm.product.property.list');
            $propertyIds = [];

            foreach ($properties['result'] as $property) {
                $propertyIds[$property['CODE']] = $property['ID'];
            }
        }

        return $propertyIds;
    }

// Метод для поиска продукта по GUID
    protected function findProductByGuid($guid, $propertyIds)
    {
        if (!isset($propertyIds['GUID_1C'])) {
            return null;
        }

        // Ищем товар по значению свойства GUID_1C
        $filter = [
            'PROPERTY_' . $propertyIds['GUID_1C'] => $guid
        ];

        $products = $this->b24Service->call('crm.product.list', [
            'filter' => $filter,
            'select' => ['ID']
        ]);

        return !empty($products['result'][0]) ? $products['result'][0]['ID'] : null;
    }

    protected function mapProductType($type): int
    {
        return match ($type) {
            'service' => 2, // Услуга
            default => 1    // Товар
        };
    }

    protected function mapVatRate($rate): int
    {
        return match ($rate) {
            'БезНДС' => 1,
            //'Общая' => 5,
            default => 7 // По умолчанию 5%
        };
    }

    protected function getB24MeasureId($unitGuid1c): int
    {
        // Получаем единицу измерения из нашей БД
        $unit = UnitOfMeasure::where('guid_1c', $unitGuid1c)->first();

        if (!$unit) {
            return 9; // Штука (по умолчанию)
        }

        // Маппинг кодов единиц измерения на ID в Б24
        return match ($unit->code) {
            '796' => 9,  // Штука
            '006' => 1,  // Метр
            '112' => 3,  // Литр
            '163' => 5,  // Грамм
            '166' => 7,  // Килограмм
            default => 9 // Штука (по умолчанию)
        };
    }
    protected function processProductGroup($change): void
    {
        $group = ProductGroup::find($change->local_id);
        if (!$group) {
            throw new \Exception("Product group not found: {$change->local_id}");
        }

        $b24Fields = [
            'NAME' => $group->name,
            'CODE' => $group->guid_1c,
            'DESCRIPTION' => $group->description ?? ''
        ];

        if ($group->parent_guid_1c) {
            // Ищем родительскую группу в Б24 по её GUID (CODE)
            $parentResult = $this->b24Service->call('crm.productsection.list', [
                'filter' => ['CODE' => $group->parent_guid_1c],
                'select' => ['ID']
            ]);

            if (!empty($parentResult['result'][0])) {
                $b24Fields['SECTION_ID'] = $parentResult['result'][0]['ID'];
            } else {
                Log::warning("Parent section not found in B24 for GUID: {$group->parent_guid_1c}");
            }
        }

        // Проверяем, существует ли уже такая группа
        $existingResult = $this->b24Service->call('crm.productsection.list', [
            'filter' => ['CODE' => $group->guid_1c],
            'select' => ['ID']
        ]);

        if (!empty($existingResult['result'][0])) {
            // Обновляем существующую
            $result = $this->b24Service->call('crm.productsection.update', [
                'id' => $existingResult['result'][0]['ID'],
                'fields' => $b24Fields
            ]);
            $b24Id = $existingResult['result'][0]['ID'];
        } else {
            // Создаем новую
            $result = $this->b24Service->call('crm.productsection.add', [
                'fields' => $b24Fields
            ]);
            $b24Id = $result['result'];
        }

        if ($b24Id) {
            $change->b24_id = $b24Id;
            $change->markProcessed();
        } else {
            throw new \Exception("Failed to create/update section in B24");
        }
    }

    protected function processOrganization(ObjectChangeLog $change): void
    {
        $processor = new OrganizationSyncProcessor($this->b24Service);
        $processor->processOrganization($change);
    }

}
