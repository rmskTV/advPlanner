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

        Log::info('Processing CustomerOrder tabular sections', [
            'order_id' => $order->id,
            'services_count' => count($servicesSection)
        ]);

        if (empty($servicesSection)) {
            Log::info('No services in tabular sections', ['order_id' => $order->id]);
            return;
        }

        // ПРОСТОЙ АЛГОРИТМ: Проходим каждую строку и обновляем по line_number
        foreach ($servicesSection as $index => $serviceRow) {
            $lineNumber = $index + 1;
            $this->upsertOrderItem($order, $serviceRow, $lineNumber);
        }
    }

    /**
     * Обновление или создание строки заказа по номеру строки
     */
    private function upsertOrderItem(CustomerOrder $order, array $serviceRow, int $lineNumber): void
    {
        Log::debug('Upserting order item', [
            'order_id' => $order->id,
            'line_number' => $lineNumber,
            'service_row_keys' => array_keys($serviceRow)
        ]);

        // Находим или создаем строку по customer_order_id + line_number
        $item = CustomerOrderItem::updateOrCreate(
            [
                'customer_order_id' => $order->id,
                'line_number' => $lineNumber
            ],
            $this->getOrderItemData($serviceRow, $lineNumber)
        );

        Log::info($item->wasRecentlyCreated ? 'Created CustomerOrderItem' : 'Updated CustomerOrderItem', [
            'order_id' => $order->id,
            'item_id' => $item->id,
            'line_number' => $lineNumber,
            'product_guid' => $item->product_guid_1c,
            'product_name' => $item->product_name,
            'amount' => $item->amount,
            'was_created' => $item->wasRecentlyCreated
        ]);
    }

    /**
     * Получение данных для строки заказа
     */
    private function getOrderItemData(array $serviceRow, int $lineNumber): array
    {
        $data = ['line_number' => $lineNumber];

        // Номенклатура - проверяем оба варианта
        $productData = $serviceRow['ДанныеНоменклатуры'] ?? $serviceRow['Номенклатура'] ?? [];
        if (!empty($productData)) {
            $data['product_guid_1c'] = $productData['Ссылка'] ?? null;
            $data['product_name'] = $productData['Наименование'] ?? $productData['НаименованиеПолное'] ?? null;

            // Единица измерения
            $unitData = $productData['ЕдиницаИзмерения'] ?? [];
            if (!empty($unitData)) {
                $data['unit_guid_1c'] = $unitData['Ссылка'] ?? null;
                $unitClassifierData = $unitData['ДанныеКлассификатора'] ?? [];
                $data['unit_name'] = $unitClassifierData['Наименование'] ?? null;
            }
        }

        // Простые поля
        $data['quantity'] = $serviceRow['Количество'] ?? null;
        $data['price'] = $serviceRow['Цена'] ?? null;
        $data['amount'] = $serviceRow['Сумма'] ?? null;
        $data['vat_amount'] = $serviceRow['СуммаНДС'] ?? null;
        $data['content'] = $serviceRow['Содержание'] ?? null;

        return $data;
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
