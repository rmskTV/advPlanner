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
            $order->responsible_name = $responsibleData['Наименование'] ?? null;
        }

        // Системные поля
        $order->deletion_mark = false;
        $order->comment = $properties['ОбщиеСвойстваОбъектовФормата']['Комментарий'] ?? null;
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
            'services_count' => count($servicesSection),
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
            'service_row_keys' => array_keys($serviceRow),
        ]);

        // Находим или создаем строку по customer_order_id + line_number
        $item = CustomerOrderItem::updateOrCreate(
            [
                'customer_order_id' => $order->id,
                'line_number' => $lineNumber,
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
            'was_created' => $item->wasRecentlyCreated,
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
        if (! empty($productData)) {
            $data['product_guid_1c'] = $productData['Ссылка'] ?? null;
            $data['product_name'] = $productData['Наименование'] ?? $productData['НаименованиеПолное'] ?? null;

            // Единица измерения
            $unitData = $productData['ЕдиницаИзмерения'] ?? [];
            if (! empty($unitData)) {
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
        if (! empty($productData)) {
            $item->product_guid_1c = $productData['Ссылка'] ?? null;
            $item->product_name = $productData['Наименование'] ?? null;

            // Единица измерения из данных номенклатуры
            $unitData = $productData['ЕдиницаИзмерения'] ?? [];
            if (! empty($unitData)) {
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

        // Получаем связанные объекты
        $organization = $laravelModel->organization;
        $counterparty = $laravelModel->counterparty_guid_1c
            ? \Modules\Accounting\app\Models\Counterparty::findByGuid1C($laravelModel->counterparty_guid_1c)
            : null;

        $contract = $laravelModel->contract_guid_1c
            ? \Modules\Accounting\app\Models\Contract::findByGuid1C($laravelModel->contract_guid_1c)
            : null;

        $currency = $laravelModel->currency_guid_1c
            ? \Modules\Accounting\app\Models\Currency::findByGuid1C($laravelModel->currency_guid_1c)
            : null;

        // ВАЖНО: Строгий порядок свойств для XDTO!
        $properties = [];

        // 1. КлючевыеСвойства
        $keyProperties = [
            'Ссылка' => $laravelModel->guid_1c,
            'Дата' => $laravelModel->date?->format('Y-m-d\TH:i:s'),  // Дата ПЕРВАЯ!
            'Номер' => $laravelModel->number,                         // Номер ВТОРОЙ!
        ];

        // Добавляем Организацию в КлючевыеСвойства
        if ($organization) {
            $keyProperties['Организация'] = [
                'Ссылка' => $organization->guid_1c,
                'Наименование' => $organization->name,
                'НаименованиеСокращенное' => $organization->name,
                'НаименованиеПолное' => $organization->full_name ?? $organization->name,
                'ИНН' => $organization->inn,
                'КПП' => $organization->kpp,
                'ЮридическоеФизическоеЛицо' => 'ЮридическоеЛицо',
            ];
        }

        $properties['КлючевыеСвойства'] = $keyProperties;

        // 2. Валюта (ОБЯЗАТЕЛЬНО перед Сумма!)
        if ($currency) {
            $properties['Валюта'] = [
                'Ссылка' => $currency->guid_1c,
                'ДанныеКлассификатора' => [
                    'Код' => $currency->code,
                    'Наименование' => $currency->name,
                ],
            ];
        } else {
            // Fallback - рубль по умолчанию
            $properties['Валюта'] = [
                'Ссылка' => 'f1a17773-5488-11e0-91e9-00e04c771318',
                'ДанныеКлассификатора' => [
                    'Код' => '643',
                    'Наименование' => 'руб.',
                ],
            ];
        }

        // 3. Сумма (ПОСЛЕ Валюты!)
        $properties['Сумма'] = $laravelModel->amount;

        // 4. Контрагент
        if ($counterparty) {
            $properties['Контрагент'] = $this->buildCounterpartySection($counterparty);
        }

        // 5. ДанныеВзаиморасчетов
        if ($contract) {
            $properties['ДанныеВзаиморасчетов'] = $this->buildSettlementDataSection(
                $laravelModel,
                $contract,
                $organization,
                $counterparty,
                $currency
            );
        }

        // 6. АдресДоставки
        $properties['АдресДоставки'] = $laravelModel->delivery_address ?? '';

        // 7. СуммаВключаетНДС
        $properties['СуммаВключаетНДС'] = $laravelModel->amount_includes_vat ? 'true' : 'false';

        // 8. БанковскийСчетОрганизации (если есть)
        if ($laravelModel->organization_bank_account_guid_1c) {
            $bankAccount = \Modules\Accounting\app\Models\BankAccount::findByGuid1C(
                $laravelModel->organization_bank_account_guid_1c
            );

            if ($bankAccount && $organization) {
                $properties['БанковскийСчетОрганизации'] = $this->buildBankAccountSection(
                    $bankAccount,
                    $organization
                );
            }
        }

// 9. Услуги (ПЕРЕД ОбщиеСвойстваОбъектовФормата!)
        $properties['Услуги'] = $this->buildServicesSection($laravelModel);

// 10. ОбщиеСвойстваОбъектовФормата (ПОСЛЕДНЕЕ!)
        if ($laravelModel->responsible_guid_1c && $laravelModel->responsible_name) {
            $properties['ОбщиеСвойстваОбъектовФормата'] = [
                'Ответственный' => [
                    'Ссылка' => $laravelModel->responsible_guid_1c,
                    'Наименование' => $laravelModel->responsible_name,
                ],
            ];
        }

        $result = [
            'type' => 'Документ.ЗаказКлиента',
            'ref' => $laravelModel->guid_1c,
            'properties' => $properties,
            'tabular_sections' => [],  // Услуги теперь в properties
        ];


        return $result;
    }

    /**
     * Построение секции Контрагента
     */
    /**
     * Построение секции Контрагента
     */
    private function buildCounterpartySection($counterparty): array
    {
        // ВАЖНО: Строгий порядок для XDTO!
        $section = [];

        // 1. Ссылка
        $section['Ссылка'] = $counterparty->guid_1c;

        // 2. Наименование
        $section['Наименование'] = $counterparty->name;

        // 3. НаименованиеПолное
        $section['НаименованиеПолное'] = $counterparty->full_name ?? $counterparty->name;

        // 4. ИНН
        if ($counterparty->inn) {
            $section['ИНН'] = $counterparty->inn;
        }

        // 5. КПП (ОБЯЗАТЕЛЬНО перед ЮридическоеФизическоеЛицо!)
        if ($counterparty->kpp) {
            $section['КПП'] = $counterparty->kpp;
        }

        // 6. ЮридическоеФизическоеЛицо
        $section['ЮридическоеФизическоеЛицо'] = $counterparty->isLegalEntity()
            ? 'ЮридическоеЛицо'
            : 'ФизическоеЛицо';

        // 7. СтранаРегистрации
        if ($counterparty->country_guid_1c) {
            $section['СтранаРегистрации'] = [
                'Ссылка' => $counterparty->country_guid_1c,
                'ДанныеКлассификатора' => [
                    'Код' => $counterparty->country_code ?? '643',
                    'Наименование' => $counterparty->country_name ?? 'РОССИЯ',
                ],
            ];
        }

        // 8. Группа контрагента
        if ($counterparty->group_guid_1c) {
            $section['Группа'] = [
                'Ссылка' => $counterparty->group_guid_1c,
                'Наименование' => $counterparty->group->name ?? '',
            ];
        }

        // 9. РегистрационныйНомер
        if ($counterparty->ogrn) {
            $section['РегистрационныйНомер'] = $counterparty->ogrn;
        }

        // 10. ИндивидуальныйПредприниматель (если это ИП)
        if (!$counterparty->isLegalEntity()) {
            $section['ИндивидуальныйПредприниматель'] = $counterparty->is_pseudoip ? 'true' : 'false';
        } else {
            $section['ИндивидуальныйПредприниматель'] = 'false';
        }

        return $section;
    }

    /**
     * Построение секции ДанныеВзаиморасчетов
     */
    /**
     * Построение секции ДанныеВзаиморасчетов
     */
    private function buildSettlementDataSection(
        $order,
        $contract,
        $organization,
        $counterparty,
        $currency
    ): array {
        $section = [];

        // Договор
        if ($contract) {
            // ВАЖНО: Строгий порядок для XDTO!
            $contractSection = [];

            // 1. Ссылка
            $contractSection['Ссылка'] = $contract->guid_1c;

            // 2. ВидДоговора
            $contractSection['ВидДоговора'] = $contract->contract_type;

            // 3. Организация (ПЕРЕД Наименование!)
            if ($organization) {
                $contractSection['Организация'] = $this->buildOrganizationSection($organization);
            }

            // 4. Контрагент (ПЕРЕД Наименование!)
            if ($counterparty) {
                $contractSection['Контрагент'] = $this->buildCounterpartySection($counterparty);
            }

            // 5. ВалютаВзаиморасчетов
            if ($currency) {
                $contractSection['ВалютаВзаиморасчетов'] = [
                    'Ссылка' => $currency->guid_1c,
                    'ДанныеКлассификатора' => [
                        'Код' => $currency->code,
                        'Наименование' => $currency->name,
                    ],
                ];
            } else {
                // Fallback - рубль по умолчанию
                $contractSection['ВалютаВзаиморасчетов'] = [
                    'Ссылка' => 'f1a17773-5488-11e0-91e9-00e04c771318',
                    'ДанныеКлассификатора' => [
                        'Код' => '643',
                        'Наименование' => 'руб.',
                    ],
                ];
            }

            // 6. РасчетыВУсловныхЕдиницах
            $contractSection['РасчетыВУсловныхЕдиницах'] = $contract->calculations_in_conditional_units ? 'true' : 'false';

            // 7. Наименование (ПОСЛЕ всех объектов!)
            $contractSection['Наименование'] = $contract->name;

            // 8. Дата
            $contractSection['Дата'] = $contract->date->format('Y-m-d');

            // 9. Номер
            $contractSection['Номер'] = $contract->number;

            $section['Договор'] = $contractSection;
        }

        // Валюта взаиморасчетов (на уровне ДанныеВзаиморасчетов)
        if ($currency) {
            $section['ВалютаВзаиморасчетов'] = [
                'Ссылка' => $currency->guid_1c,
                'ДанныеКлассификатора' => [
                    'Код' => $currency->code,
                    'Наименование' => $currency->name,
                ],
            ];
        } else {
            // Fallback - рубль по умолчанию
            $section['ВалютаВзаиморасчетов'] = [
                'Ссылка' => 'f1a17773-5488-11e0-91e9-00e04c771318',
                'ДанныеКлассификатора' => [
                    'Код' => '643',
                    'Наименование' => 'руб.',
                ],
            ];
        }

        // Курс и кратность
        $section['КурсВзаиморасчетов'] = $order->exchange_rate ?? 1;
        $section['КратностьВзаиморасчетов'] = $order->exchange_multiplier ?? 1;
        $section['РасчетыВУсловныхЕдиницах'] = $order->calculations_in_conditional_units ? 'true' : 'false';

        return $section;
    }
    /**
     * Построение секции Организации
     */
    private function buildOrganizationSection($organization): array
    {
        return [
            'Ссылка' => $organization->guid_1c,
            'Наименование' => $organization->name,
            'НаименованиеСокращенное' => $organization->name,
            'НаименованиеПолное' => $organization->full_name ?? $organization->name,
            'ИНН' => $organization->inn,
            'КПП' => $organization->kpp,
            'ЮридическоеФизическоеЛицо' => 'ЮридическоеЛицо',
        ];
    }
    /**
     * Построение секции БанковскийСчетОрганизации
     */
    private function buildBankAccountSection($bankAccount, $organization): array
    {
        $section = [
            'Ссылка' => $bankAccount->guid_1c,
            'НомерСчета' => $bankAccount->account_number,
        ];

        // Банк
        if ($bankAccount->bank_guid_1c) {
            $section['Банк'] = [
                'Ссылка' => $bankAccount->bank_guid_1c,
                'ДанныеКлассификатораБанков' => [
                    'Наименование' => $bankAccount->bank_name,
                    'БИК' => $bankAccount->bank_bik,
                    'КоррСчет' => $bankAccount->bank_correspondent_account,
                ],
            ];

            if ($bankAccount->bank_swift) {
                $section['Банк']['ДанныеКлассификатораБанков']['СВИФТБИК'] = $bankAccount->bank_swift;
            }
        }

        // Владелец счета
        $section['Владелец'] = [
            'ОрганизацииСсылка' => [
                'Ссылка' => $organization->guid_1c,
                'Наименование' => $organization->name,
                'НаименованиеСокращенное' => $organization->name,
                'НаименованиеПолное' => $organization->full_name ?? $organization->name,
                'ИНН' => $organization->inn,
                'КПП' => $organization->kpp,
                'ЮридическоеФизическоеЛицо' => 'ЮридическоеЛицо',
            ],
        ];

        return $section;
    }
    /**
     * Построение иерархии групп номенклатуры
     */
    private function buildProductGroupHierarchy($group): array
    {
        $groupSection = [
            'Ссылка' => $group->guid_1c,
            'Наименование' => $group->name,
        ];

        if ($group->code) {
            $groupSection['КодВПрограмме'] = $group->code;
        }

        // Рекурсивно добавляем родительскую группу
        if ($group->parent_guid_1c) {
            $parentGroup = \Modules\Accounting\app\Models\ProductGroup::findByGuid1C($group->parent_guid_1c);
            if ($parentGroup) {
                $groupSection['Группа'] = $this->buildProductGroupHierarchy($parentGroup);
            }
        }

        return $groupSection;
    }
    /**
     * Построение табличной части Услуги
     */
    private function buildServicesSection(CustomerOrder $order): array
    {
        $services = [];

        Log::info('Building services section', [
            'order_id' => $order->id,
            'items_count' => $order->items->count(),
            'relation_loaded' => $order->relationLoaded('items'),
        ]);

        foreach ($order->items as $item) {
            // Получаем продукт для полной информации
// Получаем продукт для полной информации
            $product = null;
            if ($item->product_guid_1c) {
                $product = \Modules\Accounting\app\Models\Product::findByGuid1C($item->product_guid_1c);
            }

// ВАЖНО: Строгий порядок для XDTO!
            $nomenclatureSection = [];

// 1. Ссылка
            $nomenclatureSection['Ссылка'] = $item->product_guid_1c;

// 2. НаименованиеПолное (ВТОРОЕ!)
            $nomenclatureSection['НаименованиеПолное'] = $item->product_name;

// 3. КодВПрограмме (ТРЕТЬЕ!)
            if ($product && $product->code) {
                $nomenclatureSection['КодВПрограмме'] = $product->code;
            }

// 4. Наименование (ЧЕТВЕРТОЕ!)
            $nomenclatureSection['Наименование'] = $item->product_name;

// 5. Группа (ПЯТОЕ!)
            if ($product && $product->group_guid_1c) {
                $group = \Modules\Accounting\app\Models\ProductGroup::findByGuid1C($product->group_guid_1c);
                if ($group) {
                    $nomenclatureSection['Группа'] = $this->buildProductGroupHierarchy($group);
                }
            }


            $serviceRow = [];

// 1. Номенклатура
            $serviceRow['Номенклатура'] = $nomenclatureSection;

// 2. Количество
            $serviceRow['Количество'] = $item->quantity;

// 3. Сумма (ПЕРЕД Цена!)
            $serviceRow['Сумма'] = $item->amount;

// 4. Цена (ПОСЛЕ Сумма!)
            $serviceRow['Цена'] = $item->price;


// 5. СтавкаНДС
// Вычисляем ставку НДС из СуммаНДС и Суммы, если не указана явно
            $vatRate = $item->vat_rate_value;

            if (!$vatRate && $item->vat_amount && $item->amount && $item->amount > 0) {
                // Вычисляем по формуле: ставка = (СуммаНДС / (Сумма - СуммаНДС)) * 100
                $vatRate = round(($item->vat_amount / ($item->amount - $item->vat_amount)) * 100);
            }

// Если все равно не получилось вычислить - ставим 5% по умолчанию
            if (!$vatRate) {
                $vatRate = 5;
            }

            $serviceRow['СтавкаНДС'] = [
                'Ставка' => $vatRate,
                'РасчетнаяСтавка' => 'false',
                'НеОблагается' => 'false',
                'ВидСтавки' => 'Общая',
                'Страна' => [
                    'Ссылка' => '90fb0551-c879-11e5-b34d-00e0433f0101',  // GUID России из классификатора
                    'ДанныеКлассификатора' => [
                        'Код' => '643',
                        'Наименование' => 'РОССИЯ',
                    ],
                ],
            ];


// 6. СуммаНДС
            $serviceRow['СуммаНДС'] = $item->vat_amount ?? 0;

// 7. Содержание
            $serviceRow['Содержание'] = $item->content ?? '';


            $services[] = $serviceRow;

            Log::debug('Added service row', [
                'product_guid' => $item->product_guid_1c,
                'product_name' => $item->product_name,
                'amount' => $item->amount,
            ]);
        }

        Log::info('Services section built', [
            'order_id' => $order->id,
            'services_count' => count($services),
        ]);

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
