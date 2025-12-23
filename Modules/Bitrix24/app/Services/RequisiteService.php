<?php

namespace Modules\Bitrix24\app\Services;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\BankAccount;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\Organization;

class RequisiteService
{
    protected $b24Service;

    // Имя поля для хранения GUID в реквизитах.
    const REQUISITE_GUID_FIELD = 'UF_CRM_GUID_1C';

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * Вспомогательный метод для очистки строк (декодирование HTML и trim)
     */
    protected function cleanString(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Используем ENT_QUOTES | ENT_HTML5 для максимальной совместимости
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // Вспомогательный метод для парсинга ФИО
    protected function parseFioFromFullName(?string $fullName): array
    {
        $fullName = $this->cleanString($fullName);
        if (empty($fullName)) {
            return ['last' => null, 'first' => null, 'second' => null];
        }
        $cleanedName = str_ireplace(['Индивидуальный предприниматель', 'ИП'], '', $fullName);
        $cleanedName = trim(preg_replace('/\s+/', ' ', $cleanedName));
        $parts = explode(' ', $cleanedName);

        return ['last' => $parts[0] ?? null, 'first' => $parts[1] ?? null, 'second' => $parts[2] ?? null];
    }

    /**
     * Определение пресета по ИНН
     */
    protected function determinePresetId(?string $inn): int
    {
        if (empty($inn)) {
            return 1;
        }

        return (strlen($inn) === 12) ? 3 : 1; // 3 = ИП, 1 = Организация
    }

    /**
     * Создание реквизита для Organization
     */
    public function createOrganizationRequisite(int $companyId, Organization $organization): int
    {
        try {
            $presetId = $this->determinePresetId($organization->inn);
            $requisiteFields = $this->prepareOrganizationRequisiteFields($organization);
            $requisiteFields['ENTITY_TYPE_ID'] = 4;
            $requisiteFields['ENTITY_ID'] = $companyId;
            $requisiteFields['PRESET_ID'] = $presetId;

            Log::info('Creating organization requisite', [
                'company_id' => $companyId,
                'guid_1c' => $organization->guid_1c,
            ]);

            $result = $this->b24Service->call('crm.requisite.add', [
                'fields' => $requisiteFields,
            ]);

            if (empty($result['result'])) {
                throw new \Exception('Failed to create requisite: '.json_encode($result));
            }

            $requisiteId = (int) $result['result'];

            // Обработка связанных сущностей
            $this->processOrganizationRelatedEntities($requisiteId, $organization);

            Log::info('Organization requisite created', ['requisite_id' => $requisiteId]);

            return $requisiteId;

        } catch (\Exception $e) {
            Log::error('Error creating organization requisite: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Обновление реквизита Organization
     */
    public function updateOrganizationRequisite(int $requisiteId, Organization $organization): void
    {
        try {
            $requisiteFields = $this->prepareOrganizationRequisiteFields($organization);

            Log::info('Updating organization requisite', ['requisite_id' => $requisiteId]);

            $this->b24Service->call('crm.requisite.update', [
                'id' => $requisiteId,
                'fields' => $requisiteFields,
            ]);

            // Синхронизация связанных сущностей
            $this->processOrganizationRelatedEntities($requisiteId, $organization);

            Log::info('Organization requisite updated', ['requisite_id' => $requisiteId]);

        } catch (\Exception $e) {
            Log::error('Error updating organization requisite: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Подготовка полей реквизита для Organization
     */
    protected function prepareOrganizationRequisiteFields(Organization $organization): array
    {
        $isIp = (strlen($organization->inn) === 12);
        $cleanedName = $this->cleanString($organization->name);
        $cleanedFullName = $this->cleanString($organization->full_name);

        $fields = [
            'NAME' => $cleanedName,
            'RQ_INN' => $organization->inn,
            self::REQUISITE_GUID_FIELD => $organization->guid_1c,
        ];

        if (! empty($organization->kpp)) {
            $fields['RQ_KPP'] = $organization->kpp;
        }
        if ($cleanedName) {
            $fields['RQ_COMPANY_NAME'] = $cleanedName;
        }
        if ($cleanedFullName) {
            $fields['RQ_COMPANY_FULL_NAME'] = $cleanedFullName;
        }
        if (! empty($organization->okpo)) {
            $fields['RQ_OKPO'] = $organization->okpo;
        }

        if (! empty($organization->ogrn)) {
            $fields[$isIp ? 'RQ_OGRNIP' : 'RQ_OGRN'] = $organization->ogrn;
        }

        if (! empty($organization->director_name)) {
            $fields['RQ_DIRECTOR'] = $this->cleanString($organization->director_name);
        }

        if ($isIp) {
            $fio = $this->parseFioFromFullName($organization->full_name ?? $organization->name);
            if ($fio['last']) {
                $fields['RQ_LAST_NAME'] = $fio['last'];
            }
            if ($fio['first']) {
                $fields['RQ_FIRST_NAME'] = $fio['first'];
            }
            if ($fio['second']) {
                $fields['RQ_SECOND_NAME'] = $fio['second'];
            }
        }

        return $fields;
    }

    /**
     * Обработка связанных сущностей для Organization
     */
    protected function processOrganizationRelatedEntities(int $requisiteId, Organization $organization): void
    {
        // Юридический адрес
        if (! empty($organization->legal_address)) {
            $this->syncAddress($requisiteId, $organization->legal_address, 1);
        }

        // Банковские счета - ЕДИНАЯ ЛОГИКА с Counterparty
        $this->syncBankAccounts($requisiteId, $organization);
    }

    protected function syncAddress(int $requisiteId, string $address, int $typeId): void
    {
        $cleanedAddress = $this->cleanString($address);

        if (empty($cleanedAddress)) {
            return;
        }

        // Ищем существующий адрес
        $existingAddress = $this->b24Service->call('crm.address.list', [
            'filter' => [
                'ENTITY_TYPE_ID' => 8, // Реквизит
                'ENTITY_ID' => $requisiteId,
                'TYPE_ID' => $typeId,
            ],
            'select' => ['ID'],
        ]);

        $addressFields = [
            'TYPE_ID' => $typeId,
            'ENTITY_TYPE_ID' => 8,
            'ENTITY_ID' => $requisiteId,
            'ADDRESS_1' => $cleanedAddress,
        ];

        try {
            if (! empty($existingAddress['result'][0]['ID'])) {
                $this->b24Service->call('crm.address.update', [
                    'id' => $existingAddress['result'][0]['ID'],
                    'fields' => $addressFields,
                ]);
            } else {
                $this->b24Service->call('crm.address.add', [
                    'fields' => $addressFields,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error syncing address: '.$e->getMessage());
        }
    }

    /**
     * Создание реквизита для компании
     */
    public function createCompanyRequisite($companyId, Counterparty $counterparty)
    {
        try {
            // 1. Подготовка полей реквизита
            $presetId = 1; // Организация
            $isIpOrPhys = (strlen($counterparty->inn) === 12);
            if ($isIpOrPhys) {
                $presetId = 3;
            } // ИП

            $cleanedName = $this->cleanString($counterparty->name);
            $cleanedFullName = $this->cleanString($counterparty->full_name);

            $requisiteFields = [
                'ENTITY_TYPE_ID' => 4, 'ENTITY_ID' => $companyId, 'PRESET_ID' => $presetId,
                'NAME' => $cleanedName,
                'RQ_INN' => $counterparty->inn,
                self::REQUISITE_GUID_FIELD => $counterparty->guid_1c,
            ];

            if ($counterparty->kpp) {
                $requisiteFields['RQ_KPP'] = $counterparty->kpp;
            }
            if ($cleanedName) {
                $requisiteFields['RQ_COMPANY_NAME'] = $cleanedName;
            }
            if ($cleanedFullName) {
                $requisiteFields['RQ_COMPANY_FULL_NAME'] = $cleanedFullName;
            }
            if ($counterparty->okpo) {
                $requisiteFields['RQ_OKPO'] = $counterparty->okpo;
            }

            if ($counterparty->ogrn) {
                if ($isIpOrPhys) {
                    $requisiteFields['RQ_OGRNIP'] = $counterparty->ogrn;
                } else {
                    $requisiteFields['RQ_OGRN'] = $counterparty->ogrn;
                }
            }

            if ($isIpOrPhys) {
                $fio = $this->parseFioFromFullName($counterparty->full_name ?? $counterparty->name);
                if ($fio['last']) {
                    $requisiteFields['RQ_LAST_NAME'] = $fio['last'];
                }
                if ($fio['first']) {
                    $requisiteFields['RQ_FIRST_NAME'] = $fio['first'];
                }
                if ($fio['second']) {
                    $requisiteFields['RQ_SECOND_NAME'] = $fio['second'];
                }
            }

            Log::info('Creating requisite with GUID', ['company_id' => $companyId, 'guid_1c' => $counterparty->guid_1c]);

            // 2. Создание реквизита в Б24
            $result = $this->b24Service->call('crm.requisite.add', ['fields' => $requisiteFields]);

            if (empty($result['result'])) {
                throw new \Exception("Failed to create requisite for company {$companyId}. API response empty.");
            }
            $requisiteId = $result['result'];

            // 3. Добавление связанных сущностей (адреса, банки)
            $this->processRelatedEntities($requisiteId, $counterparty);

            Log::info('Requisite created successfully', ['requisite_id' => $requisiteId]);

            return $requisiteId;

        } catch (\Exception $e) {
            if (! isset($result) || ! empty($result['result'])) {
                Log::error('Error creating requisite: '.$e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Обновление реквизита
     */
    public function updateCompanyRequisite($requisiteId, Counterparty $counterparty)
    {
        try {
            // 1. Подготовка полей для обновления
            $isIpOrPhys = (strlen($counterparty->inn) === 12);
            $cleanedName = $this->cleanString($counterparty->name);
            $cleanedFullName = $this->cleanString($counterparty->full_name);

            $requisiteFields = ['RQ_INN' => $counterparty->inn, self::REQUISITE_GUID_FIELD => $counterparty->guid_1c];

            if ($counterparty->kpp) {
                $requisiteFields['RQ_KPP'] = $counterparty->kpp;
            }
            if ($cleanedName) {
                $requisiteFields['RQ_COMPANY_NAME'] = $cleanedName;
            }
            if ($cleanedFullName) {
                $requisiteFields['RQ_COMPANY_FULL_NAME'] = $cleanedFullName;
            }
            if ($counterparty->okpo) {
                $requisiteFields['RQ_OKPO'] = $counterparty->okpo;
            }

            if ($counterparty->ogrn) {
                if ($isIpOrPhys) {
                    $requisiteFields['RQ_OGRNIP'] = $counterparty->ogrn;
                } else {
                    $requisiteFields['RQ_OGRN'] = $counterparty->ogrn;
                }
            }

            if ($isIpOrPhys) {
                $fio = $this->parseFioFromFullName($counterparty->full_name ?? $counterparty->name);
                $requisiteFields['RQ_LAST_NAME'] = $fio['last'];
                $requisiteFields['RQ_FIRST_NAME'] = $fio['first'];
                $requisiteFields['RQ_SECOND_NAME'] = $fio['second'];
            }

            Log::info('Updating requisite directly', ['req_id' => $requisiteId]);

            // 2. Обновление реквизита в Б24
            $this->b24Service->call('crm.requisite.update', ['id' => $requisiteId, 'fields' => $requisiteFields]);

            // 3. Синхронизация связанных сущностей (теперь с логикой обновления!)
            $this->processRelatedEntities($requisiteId, $counterparty);

            Log::info('Requisite updated successfully', ['requisite_id' => $requisiteId]);

            return $requisiteId;
        } catch (\Exception $e) {
            Log::error("Error updating requisite $requisiteId: ".$e->getMessage());
            throw $e;
        }
    }

    // =========================================================================
    // МЕТОДЫ ДЛЯ АДРЕСОВ И БАНКОВ (С ЛОГИКОЙ ОБНОВЛЕНИЯ)
    // =========================================================================

    protected function processRelatedEntities($requisiteId, Counterparty $counterparty)
    {
        // Адреса (пока оставлены простым добавлением, для них логика обновления сложнее)
        if ($counterparty->legal_address) {
            $this->addAddress($requisiteId, $counterparty->legal_address, 1);
        }
        if ($counterparty->actual_address && $counterparty->actual_address !== $counterparty->legal_address) {
            $this->addAddress($requisiteId, $counterparty->actual_address, 6);
        }

        // Банковские счета - запускаем умную синхронизацию
        $this->syncBankAccounts($requisiteId, $counterparty);
    }

    /**
     * НОВЫЙ МЕТОД: Синхронизация (создание или обновление) банковских счетов
     */
    protected function syncBankAccounts($requisiteId, Counterparty|Organization $counterparty)
    {
        $activeLocalAccounts = $counterparty->activeBankAccounts()->get();
        if ($activeLocalAccounts->isEmpty()) {
            return;
        }

        Log::info('Syncing bank accounts for requisite', ['requisite_id' => $requisiteId, 'count' => $activeLocalAccounts->count()]);

        // 1. Получаем список существующих счетов в Б24 для этого реквизита
        $existingB24Accounts = [];
        try {
            $b24List = $this->b24Service->call('crm.requisite.bankdetail.list', [
                'filter' => ['ENTITY_ID' => $requisiteId],
                'select' => ['ID', 'CODE'],
            ]);

            if (! empty($b24List['result'])) {
                foreach ($b24List['result'] as $b24Acc) {
                    if (! empty($b24Acc['CODE'])) {
                        // Создаем карту: Номер счета -> ID в Битрикс24
                        $existingB24Accounts[$b24Acc['CODE']] = $b24Acc['ID'];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to fetch existing bank accounts from B24: '.$e->getMessage());

            // Если не смогли получить список, лучше прервать синхронизацию банков, чтобы не наделать дублей
            return;
        }

        // 2. Перебираем локальные счета и решаем: создать или обновить
        foreach ($activeLocalAccounts as $localAccount) {
            $accNum = $localAccount->guid_1c;

            if (isset($existingB24Accounts[$accNum])) {
                // UPDATE: Счет с таким номером уже есть в Б24
                $b24Id = $existingB24Accounts[$accNum];
                Log::info('Bank account exists in B24, updating', ['acc_num' => $accNum, 'b24_id' => $b24Id]);
                $this->updateSingleBankAccount($b24Id, $localAccount);
                // Удаляем из карты, чтобы потом понимать, какие счета в Б24 остались лишними (если понадобится логика удаления)
                unset($existingB24Accounts[$accNum]);
            } else {
                // CREATE: Счета нет, создаем
                Log::info('Bank account new, creating', ['acc_num' => $accNum]);
                $this->createSingleBankAccount($requisiteId, $localAccount);
            }
        }
    }

    /**
     * Вспомогательный метод: подготовка полей для банка (с очисткой)
     */
    protected function prepareBankFields(BankAccount $account): array
    {
        $cleanedBankName = $this->cleanString($account->bank_name);
        $cleanedAccountName = $this->cleanString($account->name);

        $accountName = $cleanedAccountName;
        if (empty($accountName)) {
            $accountName = $cleanedBankName ? ($cleanedBankName.' '.substr($account->account_number, -4)) : 'Основной счёт';
        }

        return array_filter([
            'COUNTRY_ID' => 1, // Россия
            'NAME' => $accountName,
            'RQ_BANK_NAME' => $cleanedBankName,
            'RQ_BIK' => $account->bank_bik,
            'RQ_ACC_NUM' => $account->account_number,
            'RQ_COR_ACC_NUM' => $account->bank_correspondent_account,
            'RQ_SWIFT' => $account->bank_swift,
            'CURRENCY_ID' => 'RUB',
            'CODE' => $account->guid_1c,
        ], function ($value) {
            return ! is_null($value) && $value !== '';
        });
    }

    /**
     * Создание одного счета
     */
    protected function createSingleBankAccount($requisiteId, BankAccount $account)
    {
        $bankFields = $this->prepareBankFields($account);
        $bankFields['ENTITY_ID'] = $requisiteId; // Для создания обязательно нужен ID реквизита

        try {
            $this->b24Service->call('crm.requisite.bankdetail.add', ['fields' => $bankFields]);
        } catch (\Exception $e) {
            Log::error('Failed to create bank account: '.$e->getMessage(), ['acc_num' => $account->account_number]);
        }
    }

    /**
     * НОВЫЙ МЕТОД: Обновление одного счета
     */
    protected function updateSingleBankAccount($b24BankDetailId, BankAccount $account)
    {
        $bankFields = $this->prepareBankFields($account);
        // Для обновления ENTITY_ID не нужен, нужен ID самого банковского реквизита

        try {
            $this->b24Service->call('crm.requisite.bankdetail.update', [
                'id' => $b24BankDetailId,
                'fields' => $bankFields,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update bank account: '.$e->getMessage(), ['b24_id' => $b24BankDetailId, 'acc_num' => $account->account_number]);
        }
    }

    // Метод добавления адреса (остался простым)
    protected function addAddress($requisiteId, $address, $typeId)
    {
        if (empty($address)) {
            return;
        }
        $cleanedAddress = $this->cleanString($address); // Добавил очистку и сюда
        $addressFields = ['TYPE_ID' => $typeId, 'ENTITY_TYPE_ID' => 8, 'ENTITY_ID' => $requisiteId, 'ADDRESS_1' => $cleanedAddress];
        try {
            $this->b24Service->call('crm.address.add', ['fields' => $addressFields]);
        } catch (\Exception $e) {
            Log::error('Error adding address: '.$e->getMessage());
        }
    }
}
