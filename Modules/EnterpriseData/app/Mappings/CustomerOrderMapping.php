<?php

namespace Modules\EnterpriseData\app\Mappings;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\CustomerOrder;
use Modules\Accounting\app\Models\CustomerOrderItem;
use Modules\Accounting\app\Models\Organization;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class CustomerOrderMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Документ.ЗаказКлиента';
    }

    public function getModelClass(): string
    {
        return CustomerOrder::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        $order = new CustomerOrder;

        // Основные реквизиты из ключевых свойств
        $order->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);
        $order->number = $this->getFieldValue($keyProperties, 'Номер');

        // Дата документа
        $dateString = $this->getFieldValue($keyProperties, 'Дата');
        if (! empty($dateString)) {
            try {
                $order->date = Carbon::parse($dateString);
            } catch (\Exception $e) {
                Log::warning('Invalid order date format', [
                    'original_date' => $dateString,
                    'error' => $e->getMessage(),
                ]);
                $order->date = null;
            }
        }

        // Организация
        $organizationData = $keyProperties['Организация'] ?? [];
        if (! empty($organizationData) && isset($organizationData['Ссылка'])) {
            $organization = Organization::findByGuid1C($organizationData['Ссылка']);
            $order->organization_id = $organization?->id;
            $order->organization_guid_1c = $organizationData['Ссылка'];
        }

        // Контрагент
        $counterpartyData = $properties['Контрагент'] ?? [];
        if (! empty($counterpartyData)) {
            $order->counterparty_guid_1c = $counterpartyData['Ссылка'] ?? null;
        }

        // Валюта
        $currencyData = $properties['Валюта'] ?? [];
        if (! empty($currencyData)) {
            $order->currency_guid_1c = $currencyData['Ссылка'] ?? null;
        }

        // Сумма
        $order->amount = $this->getFieldValue($properties, 'Сумма');
        $order->amount_includes_vat = $this->getBooleanFieldValue($properties, 'СуммаВключаетНДС', true);

        // Данные взаиморасчетов
        $settlementData = $properties['ДанныеВзаиморасчетов'] ?? [];
        if (! empty($settlementData)) {
            $contractData = $settlementData['Договор'] ?? [];
            $order->contract_guid_1c = $contractData['Ссылка'] ?? null;

            $settlementCurrencyData = $settlementData['ВалютаВзаиморасчетов'] ?? [];
            $order->settlement_currency_guid_1c = $settlementCurrencyData['Ссылка'] ?? null;

            $order->exchange_rate = $settlementData['КурсВзаиморасчетов'] ?? null;
            $order->exchange_multiplier = $settlementData['КратностьВзаиморасчетов'] ?? null;
            $order->calculations_in_conditional_units = $this->getBooleanFieldValue($settlementData, 'РасчетыВУсловныхЕдиницах', false);
        }

        // Адрес доставки
        $order->delivery_address = $this->getFieldValue($properties, 'АдресДоставки');

        // Банковский счет организации
        $bankAccountData = $properties['БанковскийСчетОрганизации'] ?? [];
        if (! empty($bankAccountData)) {
            $order->organization_bank_account_guid_1c = $bankAccountData['Ссылка'] ?? null;
        }

        // Ответственный
        $responsibleData = $properties['ОбщиеСвойстваОбъектовФормата']['Ответственный'] ?? [];
        if (! empty($responsibleData)) {
            $order->responsible_guid_1c = $responsibleData['Ссылка'] ?? null;
        }

        // Системные поля
        $order->deletion_mark = false;
        $order->last_sync_at = now();

        return $order;
    }

    /**
     * Обработка табличной части после сохранения основного документа
     */
    public function processTabularSections(CustomerOrder $order, array $object1C): void
    {
        $tabularSections = $object1C['tabular_sections'] ?? [];
        $servicesSection = $tabularSections['Услуги'] ?? [];

        if ( count($servicesSection) === 0){
            Log::info('Processing CustomerOrder tabular sections', [
                'order_id' => $order->id,
                'order' => $order,
                'object' => $object1C,
                'services_count' => count($servicesSection),
                'existing_items_count' => $order->items()->count()
            ]);
        }
        else{
            Log::info('Processing CustomerOrder tabular sections', [
                'order_id' => $order->id,
                'services_count' => count($servicesSection),
                'existing_items_count' => $order->items()->count()
            ]);
        }

        if (empty($servicesSection)) {
            // Если в 1С нет строк, но у нас есть - возможно они были удалены
            // Пока оставляем как есть, не удаляем автоматически
            Log::info('No services in 1C data, keeping existing items', [
                'order_id' => $order->id
            ]);
            return;
        }

        // Получаем существующие строки
        $existingItems = $order->items()->get()->keyBy('line_number');

        // Обрабатываем строки из 1С
        foreach ($servicesSection as $index => $serviceRow) {
            $lineNumber = $index + 1;

            if ($existingItems->has($lineNumber)) {
                // Обновляем существующую строку
                $this->updateOrderItem($existingItems[$lineNumber], $serviceRow, $lineNumber);
            } else {
                // Создаем новую строку
                $this->createOrderItem($order, $serviceRow, $lineNumber);
            }
        }

        // Проверяем строки которые есть у нас, но нет в 1С
        $incomingLineNumbers = range(1, count($servicesSection));
        $extraItems = $existingItems->filter(function ($item) use ($incomingLineNumbers) {
            return !in_array($item->line_number, $incomingLineNumbers);
        });

        if ($extraItems->count() > 0) {
            Log::warning('Found extra items not present in 1C data', [
                'order_id' => $order->id,
                'extra_items_count' => $extraItems->count(),
                'extra_line_numbers' => $extraItems->pluck('line_number')->toArray()
            ]);

            $extraItems->map(function ($item) {$item->delete();})->toArray();
        }
    }

    /**
     * Обновление существующей строки заказа
     */
    private function updateOrderItem(CustomerOrderItem $item, array $serviceRow, int $lineNumber): void
    {
        $originalData = $item->getOriginal();

        // Обновляем данные
        $this->fillOrderItemData($item, $serviceRow, $lineNumber);

        // Проверяем, изменились ли данные
        $hasChanges = $item->isDirty();

        if ($hasChanges) {
            $item->save();

            Log::debug('Updated CustomerOrderItem', [
                'item_id' => $item->id,
                'line_number' => $lineNumber,
                'changes' => $item->getChanges(),
                'product_guid' => $item->product_guid_1c
            ]);
        } else {
            Log::debug('CustomerOrderItem unchanged', [
                'item_id' => $item->id,
                'line_number' => $lineNumber
            ]);
        }
    }

    /**
     * Создание новой строки заказа
     */
    private function createOrderItem(CustomerOrder $order, array $serviceRow, int $lineNumber): void
    {
        $item = new CustomerOrderItem();
        $item->customer_order_id = $order->id;

        $this->fillOrderItemData($item, $serviceRow, $lineNumber);
        $item->save();

        Log::debug('Created CustomerOrderItem', [
            'order_id' => $order->id,
            'line_number' => $lineNumber,
            'product_guid' => $item->product_guid_1c,
            'quantity' => $item->quantity,
            'amount' => $item->amount
        ]);
    }

    /**
     * Заполнение данных строки заказа (общий метод для создания и обновления)
     */
    private function fillOrderItemData(CustomerOrderItem $item, array $serviceRow, int $lineNumber): void
    {
        $item->line_number = $lineNumber;

        // Данные номенклатуры
        $productData = $serviceRow['ДанныеНоменклатуры'] ?? $serviceRow['Номенклатура'] ?? [];
        if (!empty($productData)) {
            $item->product_guid_1c = $productData['Ссылка'] ?? null;
            $item->product_name = $productData['Наименование'] ?? null;

            // Единица измерения из данных номенклатуры
            $unitData = $productData['ЕдиницаИзмерения'] ?? [];
            if (!empty($unitData)) {
                $item->unit_guid_1c = $unitData['Ссылка'] ?? null;
                $unitClassifierData = $unitData['ДанныеКлассификатора'] ?? [];
                $item->unit_name = $unitClassifierData['Наименование'] ?? null;
            }
        }

        // Количество и суммы
        $item->quantity = $serviceRow['Количество'] ?? null;
        $item->price = $serviceRow['Цена'] ?? null;
        $item->amount = $serviceRow['Сумма'] ?? null;
        $item->vat_amount = $serviceRow['СуммаНДС'] ?? null;

        // Содержание
        $item->content = $serviceRow['Содержание'] ?? null;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var CustomerOrder $laravelModel */
        return [
            'type' => 'Документ.ЗаказКлиента',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'Номер' => $laravelModel->number,
                    'Дата' => $laravelModel->date?->format('Y-m-d\TH:i:s'),
                ],
                'Сумма' => $laravelModel->amount,
                'СуммаВключаетНДС' => $laravelModel->amount_includes_vat ? 'true' : 'false',
                'АдресДоставки' => $laravelModel->delivery_address,
            ],
            'tabular_sections' => [
                'Услуги' => $this->buildServicesSection($laravelModel),
            ],
        ];
    }

    /**
     * Построение табличной части Услуги
     */
    private function buildServicesSection(CustomerOrder $order): array
    {
        $services = [];

        foreach ($order->items as $item) {
            $services[] = [
                'ДанныеНоменклатуры' => [
                    'Ссылка' => $item->product_guid_1c,
                    'Наименование' => $item->product_name,
                ],
                'Количество' => $item->quantity,
                'Цена' => $item->price,
                'Сумма' => $item->amount,
                'СуммаНДС' => $item->vat_amount,
                'Содержание' => $item->content,
            ];
        }

        return $services;
    }

    public function validateStructure(array $object1C): ValidationResult
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];
        $warnings = [];

        if (empty($keyProperties)) {
            return ValidationResult::failure(['КлючевыеСвойства section is missing']);
        }

        $number = $this->getFieldValue($keyProperties, 'Номер');
        if (empty(trim($number))) {
            $warnings[] = 'Order number is missing';
        }

        $date = $this->getFieldValue($keyProperties, 'Дата');
        if (empty($date)) {
            $warnings[] = 'Order date is missing';
        }

        return ValidationResult::success($warnings);
    }
}
