<?php

namespace Modules\EnterpriseData\app\Mappings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\BankAccount;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\Currency;
use Modules\Accounting\app\Models\Organization;
use Modules\EnterpriseData\app\Contracts\ObjectMapping;
use Modules\EnterpriseData\app\ValueObjects\ValidationResult;

class BankAccountMapping extends ObjectMapping
{
    public function getObjectType(): string
    {
        return 'Справочник.БанковскиеСчета';
    }

    public function getModelClass(): string
    {
        return BankAccount::class;
    }

    public function mapFrom1C(array $object1C): Model
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];

        $bankAccount = new BankAccount;

        // 1. GUID банковского счета
        $bankAccount->guid_1c = $this->getFieldValue($keyProperties, 'Ссылка')
            ?: ($object1C['ref'] ?? null);

        // 2. Номер счета
        $bankAccount->account_number = $this->getFieldValue($keyProperties, 'НомерСчета');

        // 3. Наименование счета
        $bankAccount->name = $this->getFieldValue($properties, 'Наименование');

        // 4. Владелец (контрагент)
        $this->processOwner($bankAccount, $keyProperties);

        // 5. Банк
        $this->processBankInfo($bankAccount, $keyProperties);

        // 6. Валюта
        $this->processCurrency($bankAccount, $properties);

        // 7. Вид счета
        $bankAccount->account_type = $this->getFieldValue($properties, 'ВидСчета');

        // 8. Настройки вывода
        $bankAccount->print_month_in_words = $this->getBooleanFieldValue(
            $properties,
            'ВыводитьМесяцПрописью',
            false
        );

        $bankAccount->print_amount_without_kopecks = $this->getBooleanFieldValue(
            $properties,
            'ВыводитьСуммуБезКопеек',
            false
        );

        // 9. Системные поля
        $bankAccount->deletion_mark = $this->getBooleanFieldValue(
            $keyProperties,
            'ПометкаУдаления',
            false
        );
        $bankAccount->is_active = ! $bankAccount->deletion_mark;
        $bankAccount->last_sync_at = now();

        return $bankAccount;
    }

    public function mapTo1C(Model $laravelModel): array
    {
        /** @var BankAccount $laravelModel */
        return [
            'type' => 'Справочник.БанковскиеСчета',
            'ref' => $laravelModel->guid_1c,
            'properties' => [
                'КлючевыеСвойства' => [
                    'Ссылка' => $laravelModel->guid_1c,
                    'НомерСчета' => $laravelModel->account_number,
                    'Банк' => [
                        'Ссылка' => $laravelModel->bank_guid_1c,
                        'ДанныеКлассификатораБанков' => [
                            'Наименование' => $laravelModel->bank_name,
                            'БИК' => $laravelModel->bank_bik,
                            'КоррСчет' => $laravelModel->bank_correspondent_account,
                            'СВИФТБИК' => $laravelModel->bank_swift,
                        ],
                    ],
                    'Владелец' => [
                        'КонтрагентыСсылка' => [
                            'Ссылка' => $laravelModel->counterparty_guid_1c
                                ?: $laravelModel->counterparty?->guid_1c,
                        ],
                    ],
                    'ПометкаУдаления' => $laravelModel->deletion_mark ? 'true' : 'false',
                ],
                'Наименование' => $laravelModel->name,
                'ВалютаДенежныхСредств' => [
                    'Ссылка' => $laravelModel->currency_guid_1c
                        ?: $laravelModel->currency?->guid_1c,
                    'ДанныеКлассификатора' => [
                        'Код' => $laravelModel->currency_code,
                    ],
                ],
                'ВыводитьМесяцПрописью' => $laravelModel->print_month_in_words ? 'true' : 'false',
                'ВыводитьСуммуБезКопеек' => $laravelModel->print_amount_without_kopecks ? 'true' : 'false',
                'ВидСчета' => $laravelModel->account_type,
            ],
            'tabular_sections' => [],
        ];
    }

    public function validateStructure(array $object1C): ValidationResult
    {
        $properties = $object1C['properties'] ?? [];
        $keyProperties = $properties['КлючевыеСвойства'] ?? [];
        $warnings = [];

        // Проверяем наличие ключевых свойств
        if (empty($keyProperties)) {
            return ValidationResult::failure(['КлючевыеСвойства section is missing']);
        }

        // Проверяем номер счета
        $accountNumber = $this->getFieldValue($keyProperties, 'НомерСчета');
        if (empty(trim($accountNumber))) {
            $warnings[] = 'Bank account number (НомерСчета) is missing';
        } elseif (! $this->isValidAccountNumber($accountNumber)) {
            $warnings[] = 'Bank account number format is invalid: '.$accountNumber;
        }

        // Проверяем банк
        $bankData = $keyProperties['Банк'] ?? [];
        if (empty($bankData)) {
            $warnings[] = 'Bank information (Банк) is missing';
        } else {
            $bankClassifierData = $bankData['ДанныеКлассификатораБанков'] ?? [];

            $bik = $bankClassifierData['БИК'] ?? null;
            if (empty($bik)) {
                $warnings[] = 'Bank BIK is missing';
            } elseif (! $this->isValidBIK($bik)) {
                $warnings[] = 'Bank BIK format is invalid: '.$bik;
            }
        }

        // Проверяем владельца
        $ownerData = $keyProperties['Владелец'] ?? [];
        $counterpartyData = $ownerData['КонтрагентыСсылка'] ?? [];

        if (empty($counterpartyData) || empty($counterpartyData['Ссылка'])) {
            $warnings[] = 'Account owner (Владелец/КонтрагентыСсылка) is missing';
        }

        // Всегда возвращаем успех, но с предупреждениями
        return ValidationResult::success($warnings);
    }

    /**
     * Обработка информации о владельце счета
     */
    private function processOwner(BankAccount $bankAccount, array $keyProperties): void
    {
        $ownerData = $keyProperties['Владелец'] ?? [];
        $counterpartyData = $ownerData['КонтрагентыСсылка'] ?? [];

        if (! empty($counterpartyData) && isset($counterpartyData['Ссылка'])) {
            $counterpartyGuid = $counterpartyData['Ссылка'];

            // Сохраняем GUID контрагента
            $bankAccount->counterparty_guid_1c = $counterpartyGuid;

            // Пытаемся найти контрагента и связать
            $counterparty = Counterparty::findByGuid1C($counterpartyGuid);
            if ($counterparty) {
                $bankAccount->counterparty_id = $counterparty->id;
            } else {
                Log::warning('Counterparty not found for bank account', [
                    'account_guid' => $bankAccount->guid_1c,
                    'counterparty_guid' => $counterpartyGuid,
                ]);
            }
        }

        $counterpartyData = $ownerData['ОрганизацииСсылка'] ?? [];

        if (! empty($counterpartyData) && isset($counterpartyData['Ссылка'])) {
            $counterpartyGuid = $counterpartyData['Ссылка'];

            // Сохраняем GUID контрагента
            $bankAccount->counterparty_guid_1c = $counterpartyGuid;

            // Пытаемся найти контрагента и связать
            $counterparty = Organization::findByGuid1C($counterpartyGuid);
            if ($counterparty) {
                $bankAccount->organization_id = $counterparty->id;
            } else {
                Log::warning('Organization not found for bank account', [
                    'account_guid' => $bankAccount->guid_1c,
                    'counterparty_guid' => $counterpartyGuid,
                ]);
            }
        }
    }

    /**
     * Обработка информации о банке
     */
    private function processBankInfo(BankAccount $bankAccount, array $keyProperties): void
    {
        $bankData = $keyProperties['Банк'] ?? [];

        if (empty($bankData)) {
            return;
        }

        // GUID банка
        $bankAccount->bank_guid_1c = $bankData['Ссылка'] ?? null;

        // Данные классификатора банков
        $bankClassifierData = $bankData['ДанныеКлассификатораБанков'] ?? [];

        if (! empty($bankClassifierData)) {
            $bankAccount->bank_name = $this->getFieldValue($bankClassifierData, 'Наименование');
            $bankAccount->bank_bik = $this->getFieldValue($bankClassifierData, 'БИК');
            $bankAccount->bank_correspondent_account = $this->getFieldValue($bankClassifierData, 'КоррСчет');
            $bankAccount->bank_swift = $this->getFieldValue($bankClassifierData, 'СВИФТБИК');
        }
    }

    /**
     * Обработка информации о валюте
     */
    private function processCurrency(BankAccount $bankAccount, array $properties): void
    {
        $currencyData = $properties['ВалютаДенежныхСредств'] ?? [];

        if (empty($currencyData)) {
            return;
        }

        // GUID валюты
        $currencyGuid = $currencyData['Ссылка'] ?? null;
        if ($currencyGuid) {
            $bankAccount->currency_guid_1c = $currencyGuid;

            // Пытаемся найти валюту и связать
            $currency = Currency::findByGuid1C($currencyGuid);
            if ($currency) {
                $bankAccount->currency_id = $currency->id;
            }
        }

        // Код валюты из классификатора
        $classifierData = $currencyData['ДанныеКлассификатора'] ?? [];
        if (! empty($classifierData)) {
            $bankAccount->currency_code = $this->getFieldValue($classifierData, 'Код');
        }
    }

    /**
     * Валидация номера банковского счета (20 цифр)
     */
    private function isValidAccountNumber(?string $accountNumber): bool
    {
        if (empty($accountNumber)) {
            return false;
        }

        // Удаляем пробелы и дефисы
        $cleaned = preg_replace('/[\s\-]/', '', $accountNumber);

        // Должен быть ровно 20 цифр
        return preg_match('/^\d{20}$/', $cleaned) === 1;
    }

    /**
     * Валидация БИК (9 цифр)
     */
    private function isValidBIK(?string $bik): bool
    {
        if (empty($bik)) {
            return false;
        }

        // Удаляем пробелы
        $cleaned = preg_replace('/\s/', '', $bik);

        // Должен быть ровно 9 цифр
        return preg_match('/^\d{9}$/', $cleaned) === 1;
    }
}
