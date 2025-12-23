<?php
// Modules/Bitrix24/app/Services/Processors/ProductSyncProcessor.php

namespace Modules\Bitrix24\app\Services\Processors;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Accounting\app\Models\Product;
use Modules\Accounting\app\Models\UnitOfMeasure;
use Modules\Bitrix24\app\Exceptions\ValidationException;

class ProductSyncProcessor extends AbstractBitrix24Processor
{
    protected array $propertyIdsCache = [];

    protected function syncEntity(ObjectChangeLog $change): void
    {
        $product = Product::find($change->local_id);

        if (!$product) {
            throw new ValidationException("Product not found: {$change->local_id}");
        }

        Log::info("Processing product", ['guid' => $product->guid_1c, 'name' => $product->name]);

        // Получаем ID категории (секции)
        $sectionId = $this->findSectionByGuid($product->group_guid_1c);

        // Подготавливаем поля
        $fields = $this->prepareProductFields($product, $sectionId);

        // Ищем существующий товар
        $existingProductId = $this->findProductByGuid($product->guid_1c);

        if ($existingProductId) {
            // UPDATE
            $this->updateProduct($existingProductId, $fields);
            $change->b24_id = $existingProductId;
        } else {
            // CREATE
            $productId = $this->createProduct($fields);
            $change->b24_id = $productId;
        }
    }

    /**
     * Подготовка полей товара
     */
    protected function prepareProductFields(Product $product, ?int $sectionId): array
    {
        $propertyIds = $this->getProductPropertyIds();

        $fields = [
            'NAME' => $this->cleanString($product->name),
            'CODE' => $product->code,
            'DESCRIPTION' => $this->cleanString($product->description) ?? '',
            'PRICE' => 0,
            'CURRENCY_ID' => 'RUB',
            'SORT' => 500,
            'VAT_ID' => $this->mapVatRate($product->vat_rate),
            'VAT_INCLUDED' => 'Y',
            'MEASURE' => $this->getB24MeasureId($product->unit_guid_1c),
        ];

        if ($sectionId) {
            $fields['SECTION_ID'] = $sectionId;
        }

        // Пользовательские свойства
        if (isset($propertyIds['GUID_1C'])) {
            $fields['PROPERTY_' . $propertyIds['GUID_1C']] = $product->guid_1c;
        }

        if (isset($propertyIds['ANALYTICS_GROUP']) && $product->analytics_group_name) {
            $fields['PROPERTY_' . $propertyIds['ANALYTICS_GROUP']] = $product->analytics_group_name;
        }

        if (isset($propertyIds['ANALYTICS_GROUP_GUID']) && $product->analytics_group_guid_1c) {
            $fields['PROPERTY_' . $propertyIds['ANALYTICS_GROUP_GUID']] = $product->analytics_group_guid_1c;
        }

        return $fields;
    }

    /**
     * Создание товара
     */
    protected function createProduct(array $fields): int
    {
        $result = $this->b24Service->call('crm.product.add', [
            'fields' => $fields
        ]);

        if (empty($result['result'])) {
            throw new \Exception("Failed to create product: " . json_encode($result));
        }

        $productId = (int)$result['result'];

        Log::info("Product created", ['b24_id' => $productId]);

        return $productId;
    }

    /**
     * Обновление товара
     */
    protected function updateProduct(int $productId, array $fields): void
    {
        $this->b24Service->call('crm.product.update', [
            'id' => $productId,
            'fields' => $fields
        ]);

        Log::debug("Product updated", ['b24_id' => $productId]);
    }

    /**
     * Поиск товара по GUID
     */
    protected function findProductByGuid(string $guid): ?int
    {
        $propertyIds = $this->getProductPropertyIds();

        if (!isset($propertyIds['GUID_1C'])) {
            return null;
        }

        $response = $this->b24Service->call('crm.product.list', [
            'filter' => ['PROPERTY_' . $propertyIds['GUID_1C'] => $guid],
            'select' => ['ID'],
            'limit' => 1
        ]);

        return $response['result'][0]['ID'] ?? null;
    }

    /**
     * Поиск секции по GUID
     */
    protected function findSectionByGuid(?string $guid): ?int
    {
        if (!$guid) {
            return null;
        }

        $response = $this->b24Service->call('crm.productsection.list', [
            'filter' => ['CODE' => $guid],
            'select' => ['ID'],
            'limit' => 1
        ]);

        return $response['result'][0]['ID'] ?? null;
    }

    /**
     * Получение ID свойств товара
     */
    protected function getProductPropertyIds(): array
    {
        return Cache::remember('b24:product_properties', 3600, function () {
            $response = $this->b24Service->call('crm.product.property.list');

            $properties = [];
            foreach ($response['result'] ?? [] as $property) {
                $properties[$property['CODE']] = (int)$property['ID'];
            }

            return $properties;
        });
    }

    /**
     * Маппинг ставки НДС
     */
    protected function mapVatRate(?string $rate): int
    {
        return match ($rate) {
            'БезНДС' => 1,
            default => 7
        };
    }

    /**
     * Получение ID единицы измерения в B24
     */
    protected function getB24MeasureId(?string $unitGuid): int
    {
        if (!$unitGuid) {
            return 9; // Штука по умолчанию
        }

        $unit = UnitOfMeasure::where('guid_1c', $unitGuid)->first();

        if (!$unit) {
            return 9;
        }

        return match ($unit->code) {
            '796' => 9,  // Штука
            '006' => 1,  // Метр
            '112' => 3,  // Литр
            '163' => 5,  // Грамм
            '166' => 7,  // Килограмм
            default => 9
        };
    }
}
