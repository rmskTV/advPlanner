<?php

namespace Modules\EnterpriseData\app\Mappings;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Organization;
use Modules\Accounting\app\Models\Sale;
use Modules\Accounting\app\Models\SaleItem;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class SaleMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Документ.РеализацияТоваровУслуг';
    }

    public function getModelClass(): string
    {
        return Sale::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        $sale = new Sale;

        // Основные реквизиты из ключевых свойств
        $sale->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);
        $sale->number = $this->getFieldValue($keyProperties, 'Номер');

        // Дата документа
        $dateString = $this->getFieldValue($keyProperties, 'Дата');
        if (! empty($dateString)) {
            try {
                $sale->date = Carbon::parse($dateString);
            } catch (\Exception $e) {
                Log::warning('Invalid sale date format', [
                    'original_date' => $dateString,
                    'error' => $e->getMessage(),
                ]);
                $sale->date = null;
            }
        }

        // Вид операции
        $sale->operation_type = $this->getFieldValue($properties, 'ВидОперации');

        // Организация
        $organizationData = $keyProperties['Организация'] ?? [];
        if (! empty($organizationData) && isset($organizationData['Ссылка'])) {
            $organization = Organization::findByGuid1C($organizationData['Ссылка']);
            $sale->organization_id = $organization?->id;
            $sale->organization_guid_1c = $organizationData['Ссылка'];
        }

        // Контрагент
        $counterpartyData = $properties['Контрагент'] ?? [];
        if (! empty($counterpartyData)) {
            $sale->counterparty_guid_1c = $counterpartyData['Ссылка'] ?? null;
        }

        // Валюта
        $currencyData = $properties['Валюта'] ?? [];
        if (! empty($currencyData)) {
            $sale->currency_guid_1c = $currencyData['Ссылка'] ?? null;
        }

        // Сумма
        $sale->amount = $this->getFieldValue($properties, 'Сумма');
        $sale->amount_includes_vat = $this->getBooleanFieldValue($properties, 'СуммаВключаетНДС', true);

        // Данные взаиморасчетов
        $settlementData = $properties['ДанныеВзаиморасчетов'] ?? [];
        if (! empty($settlementData)) {
            $contractData = $settlementData['Договор'] ?? [];
            $sale->contract_guid_1c = $contractData['Ссылка'] ?? null;

            $settlementCurrencyData = $settlementData['ВалютаВзаиморасчетов'] ?? [];
            $sale->settlement_currency_guid_1c = $settlementCurrencyData['Ссылка'] ?? null;

            $sale->exchange_rate = $settlementData['КурсВзаиморасчетов'] ?? null;
            $sale->exchange_multiplier = $settlementData['КратностьВзаиморасчетов'] ?? null;
            $sale->calculations_in_conditional_units = $this->getBooleanFieldValue($settlementData, 'РасчетыВУсловныхЕдиницах', false);
        }

        // Связанный заказ
        $orderData = $properties['Заказ'] ?? [];
        if (! empty($orderData)) {
            $sale->order_guid_1c = $orderData['Ссылка'] ?? null;
        }

        // Адрес доставки
        $sale->delivery_address = $this->getFieldValue($properties, 'АдресДоставки');

        // Налогообложение
        $sale->taxation_type = $this->getFieldValue($properties, 'Налогообложение');

        // Электронный документ
        $sale->electronic_document_type = $this->getFieldValue($properties, 'ВидЭД');

        // Способ погашения задолженности
        $sale->debt_settlement_method = $this->getFieldValue($properties, 'СпособПогашенияЗадолженности');

        // Руководитель
        $directorData = $properties['Руководитель'] ?? [];
        if (! empty($directorData)) {
            $sale->director_guid_1c = $directorData['Ссылка'] ?? null;
        }

        // Главный бухгалтер
        $accountantData = $properties['ГлавныйБухгалтер'] ?? [];
        if (! empty($accountantData)) {
            $sale->accountant_guid_1c = $accountantData['Ссылка'] ?? null;
        }

        // Банковский счет организации
        $bankAccountData = $properties['БанковскийСчетОрганизации'] ?? [];
        if (! empty($bankAccountData)) {
            $sale->organization_bank_account_guid_1c = $bankAccountData['Ссылка'] ?? null;
        }

        // Ответственный
        $responsibleData = $properties['ОбщиеСвойстваОбъектовФормата']['Ответственный'] ?? [];
        if (! empty($responsibleData)) {
            $sale->responsible_guid_1c = $responsibleData['Ссылка'] ?? null;
        }

        // Системные поля
        $sale->deletion_mark = false;
        $sale->last_sync_at = now();

        return $sale;
    }

    /**
     * Обработка табличной части после сохранения основного документа
     */
    public function processTabularSections(Sale $sale, array $object1C): void
    {
        $tabularSections = $object1C['tabular_sections'] ?? [];
        $servicesSection = $tabularSections['Услуги'] ?? [];

        Log::info('Processing Sale tabular sections', [
            'sale_id' => $sale->id,
            'services_count' => count($servicesSection),
            'existing_items_count' => $sale->items()->count()
        ]);

        if (empty($servicesSection)) {
            Log::info('No services in 1C data, keeping existing items', [
                'sale_id' => $sale->id
            ]);
            return;
        }

        // Получаем существующие строки
        $existingItems = $sale->items()->get()->keyBy('line_number');

        // Обрабатываем строки из 1С
        foreach ($servicesSection as $index => $serviceRow) {
            $lineNumber = $index + 1;

            if ($existingItems->has($lineNumber)) {
                // Обновляем существующую строку
                $this->updateSaleItem($existingItems[$lineNumber], $serviceRow, $lineNumber);
            } else {
                // Создаем новую строку
                $this->createSaleItem($sale, $serviceRow, $lineNumber);
            }
        }

        // Логируем информацию о "лишних" строках без автоматического удаления
        $incomingLineNumbers = range(1, count($servicesSection));
        $extraItems = $existingItems->filter(function ($item) use ($incomingLineNumbers) {
            return !in_array($item->line_number, $incomingLineNumbers);
        });

        if ($extraItems->count() > 0) {
            Log::warning('Found extra items not present in 1C data', [
                'sale_id' => $sale->id,
                'extra_items_count' => $extraItems->count(),
                'extra_line_numbers' => $extraItems->pluck('line_number')->toArray()
            ]);
        }

        $extraItems->map(function ($item) {$item->delete();})->toArray();
    }

    /**
     * Обновление существующей строки реализации
     */
    private function updateSaleItem(SaleItem $item, array $serviceRow, int $lineNumber): void
    {
        $this->fillSaleItemData($item, $serviceRow, $lineNumber);

        if ($item->isDirty()) {
            $item->save();

            Log::debug('Updated SaleItem', [
                'item_id' => $item->id,
                'line_number' => $lineNumber,
                'changes' => $item->getChanges()
            ]);
        }
    }

    /**
     * Создание новой строки реализации
     */
    private function createSaleItem(Sale $sale, array $serviceRow, int $lineNumber): void
    {
        $item = new SaleItem();
        $item->sale_id = $sale->id;

        $this->fillSaleItemData($item, $serviceRow, $lineNumber);
        $item->save();

        Log::debug('Created SaleItem', [
            'sale_id' => $sale->id,
            'line_number' => $lineNumber,
            'product_guid' => $item->product_guid_1c
        ]);
    }

    /**
     * Заполнение данных строки реализации
     */
    private function fillSaleItemData(SaleItem $item, array $serviceRow, int $lineNumber): void
    {
        $item->line_number = $lineNumber;
        $item->line_identifier = $serviceRow['ИдентификаторСтроки'] ?? null;

        // Номенклатура
        $productData = $serviceRow['Номенклатура'] ?? [];
        if (!empty($productData)) {
            $item->product_guid_1c = $productData['Ссылка'] ?? null;
            $item->product_name = $productData['Наименование'] ?? null;

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

        // Содержание и тип услуги
        $item->content = $serviceRow['Содержание'] ?? null;
        $item->service_type = $serviceRow['ТипУслуги'] ?? null;

        // Счета учета
        $item->income_account = $serviceRow['СчетДоходов'] ?? null;
        $item->expense_account = $serviceRow['СчетРасходов'] ?? null;
        $item->vat_account = $serviceRow['СчетУчетаНДСПоРеализации'] ?? null;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var Sale $laravelModel */
        return [
            'type' => 'Документ.РеализацияТоваровУслуг',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'Номер' => $laravelModel->number,
                    'Дата' => $laravelModel->date?->format('Y-m-d\TH:i:s'),
                ],
                'ВидОперации' => $laravelModel->operation_type,
                'Сумма' => $laravelModel->amount,
                'СуммаВключаетНДС' => $laravelModel->amount_includes_vat ? 'true' : 'false',
                'АдресДоставки' => $laravelModel->delivery_address,
                'Налогообложение' => $laravelModel->taxation_type,
                'ВидЭД' => $laravelModel->electronic_document_type,
                'СпособПогашенияЗадолженности' => $laravelModel->debt_settlement_method,
            ],
            'tabular_sections' => [
                'Услуги' => $this->buildServicesSection($laravelModel),
            ],
        ];
    }

    /**
     * Построение табличной части Услуги
     */
    private function buildServicesSection(Sale $sale): array
    {
        $services = [];

        foreach ($sale->items as $item) {
            $services[] = [
                'Номенклатура' => [
                    'Ссылка' => $item->product_guid_1c,
                    'Наименование' => $item->product_name,
                ],
                'Количество' => $item->quantity,
                'Цена' => $item->price,
                'Сумма' => $item->amount,
                'СуммаНДС' => $item->vat_amount,
                'Содержание' => $item->content,
                'ТипУслуги' => $item->service_type,
                'СчетДоходов' => $item->income_account,
                'СчетРасходов' => $item->expense_account,
                'СчетУчетаНДСПоРеализации' => $item->vat_account,
                'ИдентификаторСтроки' => $item->line_identifier,
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
            $warnings[] = 'Sale number is missing';
        }

        $date = $this->getFieldValue($keyProperties, 'Дата');
        if (empty($date)) {
            $warnings[] = 'Sale date is missing';
        }

        return ValidationResult::success($warnings);
    }
}
