<?php

namespace Modules\EnterpriseData\app\Mappings;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\BankAccount;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\Currency;
use Modules\Accounting\app\Models\CustomerOrder;
use Modules\Accounting\app\Models\Organization;
use Modules\Accounting\app\Models\Payment;
use Modules\Accounting\app\Models\PaymentItem;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class PaymentMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Документ.ПБДСРасчетыСКонтрагентами';
    }

    public function getModelClass(): string
    {
        return Payment::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];
        $commonData = $properties['ОбщиеДанные'] ?? [];

        $payment = new Payment;

        // Основные реквизиты
        $payment->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка') ?: ($object1C['ref'] ?? null);
        $payment->number = $this->getFieldValue($keyProperties, 'Номер');

        // Дата документа
        $dateString = $this->getFieldValue($keyProperties, 'Дата');
        if (!empty($dateString)) {
            try {
                $payment->date = Carbon::parse($dateString);
            } catch (\Exception $e) {
                Log::warning('Invalid payment date format', [
                    'original_date' => $dateString,
                    'error' => $e->getMessage(),
                ]);
                $payment->date = null;
            }
        }

        // Организация
        $organizationData = $keyProperties['Организация'] ?? [];
        if (!empty($organizationData) && isset($organizationData['Ссылка'])) {
            $organization = Organization::findByGuid1C($organizationData['Ссылка']);
            $payment->organization_id = $organization?->id;
            $payment->organization_guid_1c = $organizationData['Ссылка'];
        }

        // Контрагент
        $counterpartyData = $properties['Контрагент'] ?? [];
        if (!empty($counterpartyData) && isset($counterpartyData['Ссылка'])) {
            $counterparty = Counterparty::findByGuid1C($counterpartyData['Ссылка']);
            $payment->counterparty_id = $counterparty?->id;
            $payment->counterparty_guid_1c = $counterpartyData['Ссылка'];
        }

        // Вид расчетов (тип платежа)
        $calcType = $this->getFieldValue($properties, 'ВидРасчетов');
        $payment->payment_type = ($calcType === 'СПокупателем')
            ? Payment::TYPE_INCOMING
            : Payment::TYPE_OUTGOING;

        // Сумма
        $payment->amount = $this->getFieldValue($commonData, 'Сумма');

        // Валюта
        $currencyData = $commonData['Валюта'] ?? [];
        if (!empty($currencyData) && isset($currencyData['Ссылка'])) {
            $currency = Currency::findByGuid1C($currencyData['Ссылка']);
            $payment->currency_id = $currency?->id;
            $payment->currency_guid_1c = $currencyData['Ссылка'];
        }

        // Дата выписки
        $statementDateString = $this->getFieldValue($commonData, 'ДатаВыписки');
        if (!empty($statementDateString)) {
            try {
                $payment->statement_date = Carbon::parse($statementDateString);
            } catch (\Exception $e) {
                $payment->statement_date = null;
            }
        }

        // Назначение платежа
        $payment->payment_purpose = $this->getFieldValue($commonData, 'НазначениеПлатежа');

        // Входящий документ
        $incomingDateString = $this->getFieldValue($commonData, 'ДатаВходящегоДокумента');
        if (!empty($incomingDateString)) {
            try {
                $payment->incoming_document_date = Carbon::parse($incomingDateString);
            } catch (\Exception $e) {
                $payment->incoming_document_date = null;
            }
        }
        $payment->incoming_document_number = $this->getFieldValue($commonData, 'НомерВходящегоДокумента');

        // Банковский счет организации
        $orgBankAccountData = $commonData['БанковскийСчетОрганизации'] ?? [];
        if (!empty($orgBankAccountData) && isset($orgBankAccountData['Ссылка'])) {
            $orgBankAccount = BankAccount::findByGuid1C($orgBankAccountData['Ссылка']);
            $payment->organization_bank_account_id = $orgBankAccount?->id;
            $payment->organization_bank_account_guid_1c = $orgBankAccountData['Ссылка'];
        }

        // Банковский счет контрагента
        $counterpartyBankAccountData = $properties['БанковскийСчетКонтрагента'] ?? [];
        if (!empty($counterpartyBankAccountData) && isset($counterpartyBankAccountData['Ссылка'])) {
            $counterpartyBankAccount = BankAccount::findByGuid1C($counterpartyBankAccountData['Ссылка']);
            $payment->counterparty_bank_account_id = $counterpartyBankAccount?->id;
            $payment->counterparty_bank_account_guid_1c = $counterpartyBankAccountData['Ссылка'];
        }

        // Ответственный
        $responsibleData = $commonData['Ответственный']
            ?? $properties['ОбщиеСвойстваОбъектовФормата']['Ответственный']
            ?? [];
        if (!empty($responsibleData)) {
            $payment->responsible_guid_1c = $responsibleData['Ссылка'] ?? null;
            $payment->responsible_name = $responsibleData['Наименование'] ?? null;
        }

        // Системные поля
        $payment->deletion_mark = false;
        $payment->last_sync_at = now();

        return $payment;
    }

    /**
     * Обработка расшифровки платежа после сохранения основного документа
     * (по аналогии с processTabularSections в CustomerOrderMapping)
     */
    public function processTabularSections(Payment $payment, array $object1C): void
    {
        // ⭐ ИЩЕМ РасшифровкаПлатежа во всех возможных местах
        $detailsData = $this->findPaymentDetails($object1C);

        Log::info('Processing Payment tabular sections', [
            'payment_id' => $payment->id,
            'payment_guid' => $payment->guid_1c,
            'found_details' => !empty($detailsData),
            'details_keys' => $detailsData ? array_keys($detailsData) : null,
        ]);

        if (empty($detailsData)) {
            Log::warning('No payment details found anywhere', [
                'payment_id' => $payment->id,
                'object1C_keys' => array_keys($object1C),
                'properties_keys' => isset($object1C['properties']) ? array_keys($object1C['properties']) : null,
            ]);
            return;
        }

        // Извлекаем строки
        $rows = $this->extractRows($detailsData);

        Log::info('Extracted payment detail rows', [
            'payment_id' => $payment->id,
            'rows_count' => count($rows),
        ]);

        if (empty($rows)) {
            Log::warning('No payment detail rows extracted', [
                'payment_id' => $payment->id,
                'detailsData' => json_encode($detailsData, JSON_UNESCAPED_UNICODE),
            ]);
            return;
        }

        // Обрабатываем строки
        foreach ($rows as $index => $row) {
            $lineNumber = $index + 1;
            $this->upsertPaymentItem($payment, $row, $lineNumber);
        }
    }

    /**
     * Поиск РасшифровкаПлатежа в разных местах структуры
     */
    private function findPaymentDetails(array $object1C): ?array
    {
        // Вариант 1: В properties (для JSON)
        if (isset($object1C['properties']['РасшифровкаПлатежа'])) {
            Log::info('Found РасшифровкаПлатежа in properties');
            return $object1C['properties']['РасшифровкаПлатежа'];
        }

        // Вариант 2: На верхнем уровне (возможно для XML)
        if (isset($object1C['РасшифровкаПлатежа'])) {
            Log::info('Found РасшифровкаПлатежа at top level');
            return $object1C['РасшифровкаПлатежа'];
        }

        // Вариант 3: В tabular_sections (как у заказов)
        if (isset($object1C['tabular_sections']['РасшифровкаПлатежа'])) {
            Log::info('Found РасшифровкаПлатежа in tabular_sections');
            return $object1C['tabular_sections']['РасшифровкаПлатежа'];
        }

        // Вариант 4: Альтернативное написание (на всякий случай)
        $properties = $object1C['properties'] ?? [];
        foreach ($properties as $key => $value) {
            if (stripos($key, 'расшифровка') !== false || stripos($key, 'rashifrovka') !== false) {
                Log::info("Found payment details with alternative key: {$key}");
                return $value;
            }
        }

        // Вариант 5: Рекурсивный поиск во всей структуре
        $found = $this->recursiveFind($object1C, 'РасшифровкаПлатежа');
        if ($found) {
            Log::info('Found РасшифровкаПлатежа via recursive search');
            return $found;
        }

        Log::warning('РасшифровкаПлатежа not found anywhere');
        return null;
    }

    /**
     * Рекурсивный поиск ключа в структуре
     */
    private function recursiveFind(array $array, string $searchKey, int $depth = 0, int $maxDepth = 5): ?array
    {
        if ($depth > $maxDepth) {
            return null;
        }

        if (isset($array[$searchKey]) && is_array($array[$searchKey])) {
            return $array[$searchKey];
        }

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = $this->recursiveFind($value, $searchKey, $depth + 1, $maxDepth);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Извлечение строк из РасшифровкаПлатежа
     */
    private function extractRows(array $detailsData): array
    {
        // ВАРИАНТ 1: Уже массив строк с числовыми ключами [0, 1, 2, ...]
        // Это происходит при парсинге XML когда есть несколько <Строка>
        if (isset($detailsData[0]) && is_array($detailsData[0])) {
            Log::info('РасшифровкаПлатежа is already an array of rows', [
                'count' => count($detailsData),
            ]);
            return $detailsData;
        }

        // ВАРИАНТ 2: Структура с ключом "Строка"
        if (isset($detailsData['Строка'])) {
            $strokaData = $detailsData['Строка'];

            if (!is_array($strokaData)) {
                Log::warning('Строка is not an array', [
                    'type' => gettype($strokaData),
                ]);
                return [];
            }

            // Если это ассоциативный массив - один объект
            if ($this->isAssociativeArray($strokaData)) {
                Log::info('Found single row in Строка');
                return [$strokaData];
            }

            // Массив объектов
            Log::info('Found multiple rows in Строка', ['count' => count($strokaData)]);
            return $strokaData;
        }

        // ВАРИАНТ 3: Один объект без обертки (ассоциативный массив)
        if ($this->isAssociativeArray($detailsData) && isset($detailsData['Заказ'])) {
            Log::info('РасшифровкаПлатежа is a single row object');
            return [$detailsData];
        }

        Log::warning('Could not determine РасшифровкаПлатежа structure', [
            'keys' => array_keys($detailsData),
            'first_key_type' => isset($detailsData[0]) ? gettype($detailsData[0]) : 'none',
        ]);

        return [];
    }
    /**
     * Проверка, является ли массив ассоциативным (объектом)
     */
    private function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Обновление или создание строки расшифровки платежа по номеру строки
     */
    private function upsertPaymentItem(Payment $payment, array $row, int $lineNumber): void
    {
        Log::debug('Upserting payment item', [
            'payment_id' => $payment->id,
            'line_number' => $lineNumber,
            'row_keys' => array_keys($row),
        ]);

        // Находим или создаем строку по payment_id + line_number
        $item = PaymentItem::updateOrCreate(
            [
                'payment_id' => $payment->id,
                'line_number' => $lineNumber,
            ],
            $this->getPaymentItemData($row)
        );

        Log::info($item->wasRecentlyCreated ? 'Created PaymentItem' : 'Updated PaymentItem', [
            'payment_id' => $payment->id,
            'item_id' => $item->id,
            'line_number' => $lineNumber,
            'amount' => $item->amount,
            'order_guid' => $item->order_guid_1c,
            'was_created' => $item->wasRecentlyCreated,
        ]);
    }

    /**
     * Получение данных для строки расшифровки платежа
     */
    private function getPaymentItemData(array $row): array
    {
        $data = [];

        // Заказ клиента
        $orderData = $row['Заказ']['ЗаказКлиента'] ?? [];
        if (!empty($orderData) && isset($orderData['Ссылка'])) {
            $order = CustomerOrder::findByGuid1C($orderData['Ссылка']);
            $data['order_id'] = $order?->id;
            $data['order_guid_1c'] = $orderData['Ссылка'];
        }

        // Простые поля - суммы
        $data['amount'] = $row['Сумма'] ?? null;
        $data['vat_amount'] = $row['СуммаНДС'] ?? null;
        $data['settlement_amount'] = $row['СуммаВзаиморасчетов'] ?? null;

        // Статья ДДС
        $cashFlowData = $row['СтатьяДДС'] ?? [];
        if (!empty($cashFlowData)) {
            $data['cash_flow_item_guid_1c'] = $cashFlowData['Ссылка'] ?? null;
            $data['cash_flow_item_code'] = $cashFlowData['КодВПрограмме'] ?? null;
            $data['cash_flow_item_name'] = $cashFlowData['Наименование'] ?? null;
        }

        // Данные взаиморасчетов
        $settlementData = $row['ДанныеВзаиморасчетов'] ?? [];
        if (!empty($settlementData)) {
            // Договор
            $contractData = $settlementData['Договор'] ?? [];
            if (!empty($contractData) && isset($contractData['Ссылка'])) {
                $contract = Contract::findByGuid1C($contractData['Ссылка']);
                $data['contract_id'] = $contract?->id;
                $data['contract_guid_1c'] = $contractData['Ссылка'];
            }

            // Валюта взаиморасчетов
            $currencyData = $settlementData['ВалютаВзаиморасчетов'] ?? [];
            if (!empty($currencyData) && isset($currencyData['Ссылка'])) {
                $currency = Currency::findByGuid1C($currencyData['Ссылка']);
                $data['settlement_currency_id'] = $currency?->id;
                $data['settlement_currency_guid_1c'] = $currencyData['Ссылка'];
            }

            $data['exchange_rate'] = $settlementData['КурсВзаиморасчетов'] ?? null;
            $data['exchange_multiplier'] = $settlementData['КратностьВзаиморасчетов'] ?? null;
            $data['advance_account'] = $settlementData['СчетУчетаРасчетовПоАвансам'] ?? null;
            $data['settlement_account'] = $settlementData['СчетУчетаРасчетовСКонтрагентом'] ?? null;
        }

        // Вид расчетов расширенный
        $extendedTypeData = $row['ВидРасчетовРасширенный'] ?? [];
        if (!empty($extendedTypeData)) {
            $data['payment_type_extended'] = $extendedTypeData['ВидРасчетовСПокупателямиПоставщиками'] ?? null;
        }

        // Способ погашения задолженности
        $data['debt_repayment_method'] = $row['СпособПогашенияЗадолженности'] ?? null;

        return $data;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var Payment $laravelModel */
        $calcType = $laravelModel->isIncoming() ? 'СПокупателем' : 'СПоставщиком';

        return [
            'type' => 'Документ.ПБДСРасчетыСКонтрагентами',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'Номер' => $laravelModel->number,
                    'Дата' => $laravelModel->date?->format('Y-m-d\TH:i:s'),
                ],
                'ВидРасчетов' => $calcType,
                'ОбщиеДанные' => [
                    'Сумма' => $laravelModel->amount,
                    'ДатаВыписки' => $laravelModel->statement_date?->format('Y-m-d'),
                    'НазначениеПлатежа' => $laravelModel->payment_purpose,
                    'ДатаВходящегоДокумента' => $laravelModel->incoming_document_date?->format('Y-m-d'),
                    'НомерВходящегоДокумента' => $laravelModel->incoming_document_number,
                ],
                'РасшифровкаПлатежа' => [
                    'Строка' => $this->buildPaymentDetailsSection($laravelModel),
                ],
            ],
        ];
    }

    /**
     * Построение раздела РасшифровкаПлатежа
     */
    private function buildPaymentDetailsSection(Payment $payment): array
    {
        $rows = [];

        foreach ($payment->items as $item) {
            $row = [
                'Сумма' => $item->amount,
                'СуммаНДС' => $item->vat_amount,
                'СуммаВзаиморасчетов' => $item->settlement_amount,
            ];

            if ($item->order_guid_1c) {
                $row['Заказ']['ЗаказКлиента']['Ссылка'] = $item->order_guid_1c;
            }

            if ($item->cash_flow_item_guid_1c) {
                $row['СтатьяДДС'] = [
                    'Ссылка' => $item->cash_flow_item_guid_1c,
                    'КодВПрограмме' => $item->cash_flow_item_code,
                    'Наименование' => $item->cash_flow_item_name,
                ];
            }

            if ($item->contract_guid_1c) {
                $row['ДанныеВзаиморасчетов']['Договор']['Ссылка'] = $item->contract_guid_1c;
            }

            $rows[] = $row;
        }

        // Если одна строка - возвращаем объект, если несколько - массив
        return count($rows) === 1 ? $rows[0] : $rows;
    }

    public function validateStructure(array $object1C): ValidationResult
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];
        $commonData = $properties['ОбщиеДанные'] ?? [];
        $warnings = [];

        if (empty($keyProperties)) {
            return ValidationResult::failure(['КлючевыеСвойства section is missing']);
        }

        $number = $this->getFieldValue($keyProperties, 'Номер');
        if (empty(trim($number))) {
            $warnings[] = 'Payment number is missing';
        }

        $date = $this->getFieldValue($keyProperties, 'Дата');
        if (empty($date)) {
            $warnings[] = 'Payment date is missing';
        }

        $amount = $this->getFieldValue($commonData, 'Сумма');
        if (empty($amount) || $amount <= 0) {
            $warnings[] = 'Payment amount is missing or invalid';
        }

        return ValidationResult::success($warnings);
    }
}
