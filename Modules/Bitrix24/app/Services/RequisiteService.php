<?php
namespace Modules\Bitrix24\app\Services;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\BankAccount;

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
        if (empty($value)) return null;
        // Используем ENT_QUOTES | ENT_HTML5 для максимальной совместимости
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // Вспомогательный метод для парсинга ФИО
    protected function parseFioFromFullName(?string $fullName): array
    {
        $fullName = $this->cleanString($fullName);
        if (empty($fullName)) return ['last' => null, 'first' => null, 'second' => null];
        $cleanedName = str_ireplace(['Индивидуальный предприниматель', 'ИП'], '', $fullName);
        $cleanedName = trim(preg_replace('/\s+/', ' ', $cleanedName));
        $parts = explode(' ', $cleanedName);
        return ['last' => $parts[0] ?? null, 'first' => $parts[1] ?? null, 'second' => $parts[2] ?? null];
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
            if ($isIpOrPhys) $presetId = 3; // ИП

            $cleanedName = $this->cleanString($counterparty->name);
            $cleanedFullName = $this->cleanString($counterparty->full_name);

            $requisiteFields = [
                'ENTITY_TYPE_ID' => 4, 'ENTITY_ID' => $companyId, 'PRESET_ID' => $presetId,
                'NAME' => $cleanedName,
                'RQ_INN' => $counterparty->inn,
                self::REQUISITE_GUID_FIELD => $counterparty->guid_1c
            ];

            if ($counterparty->kpp) $requisiteFields['RQ_KPP'] = $counterparty->kpp;
            if ($cleanedName) $requisiteFields['RQ_COMPANY_NAME'] = $cleanedName;
            if ($cleanedFullName) $requisiteFields['RQ_COMPANY_FULL_NAME'] = $cleanedFullName;
            if ($counterparty->okpo) $requisiteFields['RQ_OKPO'] = $counterparty->okpo;

            if ($counterparty->ogrn) {
                if ($isIpOrPhys) $requisiteFields['RQ_OGRNIP'] = $counterparty->ogrn;
                else $requisiteFields['RQ_OGRN'] = $counterparty->ogrn;
            }

            if ($isIpOrPhys) {
                $fio = $this->parseFioFromFullName($counterparty->full_name ?? $counterparty->name);
                if ($fio['last']) $requisiteFields['RQ_LAST_NAME'] = $fio['last'];
                if ($fio['first']) $requisiteFields['RQ_FIRST_NAME'] = $fio['first'];
                if ($fio['second']) $requisiteFields['RQ_SECOND_NAME'] = $fio['second'];
            }

            Log::info("Creating requisite with GUID", ['company_id' => $companyId, 'guid_1c' => $counterparty->guid_1c]);

            // 2. Создание реквизита в Б24
            $result = $this->b24Service->call('crm.requisite.add', ['fields' => $requisiteFields]);

            if (empty($result['result'])) {
                throw new \Exception("Failed to create requisite for company {$companyId}. API response empty.");
            }
            $requisiteId = $result['result'];

            // 3. Добавление связанных сущностей (адреса, банки)
            $this->processRelatedEntities($requisiteId, $counterparty);

            Log::info("Requisite created successfully", ['requisite_id' => $requisiteId]);
            return $requisiteId;

        } catch (\Exception $e) {
            if (!isset($result) || !empty($result['result'])) Log::error("Error creating requisite: " . $e->getMessage());
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

            if ($counterparty->kpp) $requisiteFields['RQ_KPP'] = $counterparty->kpp;
            if ($cleanedName) $requisiteFields['RQ_COMPANY_NAME'] = $cleanedName;
            if ($cleanedFullName) $requisiteFields['RQ_COMPANY_FULL_NAME'] = $cleanedFullName;
            if ($counterparty->okpo) $requisiteFields['RQ_OKPO'] = $counterparty->okpo;

            if ($counterparty->ogrn) {
                if ($isIpOrPhys) $requisiteFields['RQ_OGRNIP'] = $counterparty->ogrn;
                else $requisiteFields['RQ_OGRN'] = $counterparty->ogrn;
            }

            if ($isIpOrPhys) {
                $fio = $this->parseFioFromFullName($counterparty->full_name ?? $counterparty->name);
                $requisiteFields['RQ_LAST_NAME'] = $fio['last'];
                $requisiteFields['RQ_FIRST_NAME'] = $fio['first'];
                $requisiteFields['RQ_SECOND_NAME'] = $fio['second'];
            }

            Log::info("Updating requisite directly", ['req_id' => $requisiteId]);

            // 2. Обновление реквизита в Б24
            $this->b24Service->call('crm.requisite.update', ['id' => $requisiteId, 'fields' => $requisiteFields]);

            // 3. Синхронизация связанных сущностей (теперь с логикой обновления!)
            $this->processRelatedEntities($requisiteId, $counterparty);

            Log::info("Requisite updated successfully", ['requisite_id' => $requisiteId]);
            return $requisiteId;
        } catch (\Exception $e) {
            Log::error("Error updating requisite $requisiteId: " . $e->getMessage()); throw $e;
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
    protected function syncBankAccounts($requisiteId, Counterparty $counterparty)
    {
        $activeLocalAccounts = $counterparty->activeBankAccounts()->get();
        if ($activeLocalAccounts->isEmpty()) return;

        Log::info("Syncing bank accounts for requisite", ['requisite_id' => $requisiteId, 'count' => $activeLocalAccounts->count()]);

        // 1. Получаем список существующих счетов в Б24 для этого реквизита
        $existingB24Accounts = [];
        try {
            $b24List = $this->b24Service->call('crm.requisite.bankdetail.list', [
                'filter' => ['ENTITY_ID' => $requisiteId],
                'select' => ['ID', 'RQ_ACC_NUM'] // Нам нужен ID и номер счета для сопоставления
            ]);

            if (!empty($b24List['result'])) {
                foreach ($b24List['result'] as $b24Acc) {
                    if (!empty($b24Acc['RQ_ACC_NUM'])) {
                        // Создаем карту: Номер счета -> ID в Битрикс24
                        $existingB24Accounts[$b24Acc['RQ_ACC_NUM']] = $b24Acc['ID'];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to fetch existing bank accounts from B24: " . $e->getMessage());
            // Если не смогли получить список, лучше прервать синхронизацию банков, чтобы не наделать дублей
            return;
        }

        // 2. Перебираем локальные счета и решаем: создать или обновить
        foreach ($activeLocalAccounts as $localAccount) {
            $accNum = $localAccount->account_number;

            if (isset($existingB24Accounts[$accNum])) {
                // UPDATE: Счет с таким номером уже есть в Б24
                $b24Id = $existingB24Accounts[$accNum];
                Log::info("Bank account exists in B24, updating", ['acc_num' => $accNum, 'b24_id' => $b24Id]);
                $this->updateSingleBankAccount($b24Id, $localAccount);
                // Удаляем из карты, чтобы потом понимать, какие счета в Б24 остались лишними (если понадобится логика удаления)
                unset($existingB24Accounts[$accNum]);
            } else {
                // CREATE: Счета нет, создаем
                Log::info("Bank account new, creating", ['acc_num' => $accNum]);
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
            $accountName = $cleanedBankName ? ($cleanedBankName . ' ' . substr($account->account_number, -4)) : 'Основной счёт';
        }

        return array_filter([
            'COUNTRY_ID' => 1, // Россия
            'NAME' => $accountName,
            'RQ_BANK_NAME' => $cleanedBankName,
            'RQ_BIK' => $account->bank_bik,
            'RQ_ACC_NUM' => $account->account_number,
            'RQ_COR_ACC_NUM' => $account->bank_correspondent_account,
            'RQ_SWIFT' => $account->bank_swift,
            'CURRENCY_ID' => 'RUB'
        ], function($value) { return !is_null($value) && $value !== ''; });
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
            Log::error("Failed to create bank account: " . $e->getMessage(), ['acc_num' => $account->account_number]);
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
                'fields' => $bankFields
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to update bank account: " . $e->getMessage(), ['b24_id' => $b24BankDetailId, 'acc_num' => $account->account_number]);
        }
    }

    // Метод добавления адреса (остался простым)
    protected function addAddress($requisiteId, $address, $typeId)
    {
        if (empty($address)) return;
        $cleanedAddress = $this->cleanString($address); // Добавил очистку и сюда
        $addressFields = ['TYPE_ID' => $typeId, 'ENTITY_TYPE_ID' => 8, 'ENTITY_ID' => $requisiteId, 'ADDRESS_1' => $cleanedAddress];
        try {
            $this->b24Service->call('crm.address.add', ['fields' => $addressFields]);
        } catch (\Exception $e) { Log::error("Error adding address: " . $e->getMessage()); }
    }
}
