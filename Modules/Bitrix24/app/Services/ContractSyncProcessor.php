<?php

namespace Modules\Bitrix24\app\Services;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ContactPerson;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Accounting\app\Models\Contract;
use Modules\EnterpriseData\app\Services\ContactInfoParser;

// Ваша локальная модель Договора

class ContractSyncProcessor
{
    protected Bitrix24Service $b24Service;

    const USER_GUID_FIELD = 'UF_USR_1C_GUID';
    // Символьный код Смарт-процесса
    const SPA_CODE = 'contract_registry';
    const SPA_ID = 1064;
    const  SPA_ID_FOR_FIELD = 19;
    protected ?array $usersCache = null; // Кэш пользователей
    // Имя кастомного поля в сущности "Реквизит" (crm.requisite), где хранится GUID 1С
    // Должно совпадать с тем, что используется в других процессорах
    const REQUISITE_GUID_FIELD = 'UF_CRM_GUID_1C';

    // Суффиксы полей Смарт-процесса
    const FIELD_SUFFIXES = [
        'NUMBER'       => 'CONTRACT_NO',
        'DATE'         => 'CONTRACT_DATE',
        'BASIS'        => 'SIGNER_BASIS',
        'GUID_1C'      => 'GUID_1C',
        'IS_EDO'       => 'IS_EDO',
        'IS_ANNULLED'  => 'IS_ANNULLED',
    ];

    // Кэши
    protected array $requisiteMapCache = []; // guid -> ['ID' => reqId, 'ENTITY_ID' => companyId]
    protected array $contactMapCache = [];

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * @throws \Exception
     */
    public function processContract(ObjectChangeLog $change): void
    {
        $localContract = Contract::find($change->local_id);
        if (!$localContract) {
            throw new \Exception("Local contract not found: {$change->local_id}");
        }

        if (empty($localContract->guid_1c)) {
            Log::warning("Skipping contract without GUID_1C", ['id' => $localContract->id]);
            return;
        }

        // Если нет GUID контрагента (реквизита), мы не можем привязать договор к компании
        if (empty($localContract->counterparty_guid_1c)) {
            throw new \Exception("Critical: Contract {$localContract->guid_1c} has no counterparty_guid_1c");
        }

        Log::info("Processing Contract sync", ['guid' => $localContract->guid_1c]);

        // 2. Поиск зависимостей (Реквизит -> Компания)
        // Ищем реквизит по GUID, чтобы получить ID головной Компании
        $requisiteData = $this->findRequisiteByGuid($localContract->counterparty_guid_1c);

        if (!$requisiteData || empty($requisiteData['ENTITY_ID'])) {
            // Критическая ошибка: если реквизит еще не синхронизирован, договор создать нельзя.
            // Выбрасываем исключение для повторной попытки позже.
            throw new \Exception("Dependency not found: B24 Requisite/Company for GUID {$localContract->counterparty_guid_1c}");
        }

        // ENTITY_ID реквизита - это и есть ID Компании в Б24
        $b24CompanyId = (int)$requisiteData['ENTITY_ID'];

        // Поиск контакта (опционально)
        $b24ContactId = null;
        $localContactPerson = ContactPerson::where('counterparty_guid_1c', $localContract->counterparty_guid_1c)->first();
        if($localContactPerson){
            $b24ContactId = $this->findB24ContactId($localContactPerson->guid_1c);
        }
        if (!$b24ContactId) {
            Log::warning("Dependency warning: B24 Contact not found for GUID {$localContract->contact_guid_1c}");
        }

        // 3. Подготовка данных
        $fields = $this->mapContractToB24Fields($localContract, $b24CompanyId, $b24ContactId);

        // 4. Поиск существующего договора в Б24 и CREATE/UPDATE
        $existingB24ItemId = $this->findB24ContractIdByGuid($localContract->guid_1c);

        if ($existingB24ItemId) {
            //Log::info("Updating existing SPA item", ['b24_id' => $existingB24ItemId]);
            $result = $this->b24Service->call('crm.item.update', [
                'entityTypeId' => self::SPA_ID,
                'id' => $existingB24ItemId,
                'fields' => $fields,
                'useOriginalUfNames' => 'Y'
            ]);
            $b24ItemId = $existingB24ItemId; // При update ID остается тем же
        } else {
            //Log::info("Creating new SPA item");
            $result = $this->b24Service->call('crm.item.add', [
                'entityTypeId' => self::SPA_ID,
                'fields' => $fields,
                'useOriginalUfNames' => 'Y'
            ]);
            $b24ItemId = $result['result']['item']['id'] ?? null; // При create получаем новый ID
        }

        if (empty($result['result'])) {
            throw new \Exception("B24 API Error: " . ($result['error_description'] ?? json_encode($result)));
        }

        if (!$b24ItemId && isset($result['result']['item']['id'])) {
            $b24ItemId = $result['result']['item']['id'];
        }

        if ($b24ItemId) {
            $change->b24_id = $b24ItemId;
            $change->markProcessed();
            Log::info("Contract sync successful", ['b24_id' => $b24ItemId]);
        } else {
            throw new \Exception("Failed to get B24 item ID from response");
        }
    }


    // =========================================================================
    // ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // =========================================================================

    protected function getFieldName(string $suffixKey): string
    {
        // Жесткий формат: UF_CRM_{ID}_{SUFFIX}
        return "UF_CRM_" . self::SPA_ID_FOR_FIELD . "_" . self::FIELD_SUFFIXES[$suffixKey];
    }

    protected function mapContractToB24Fields(Contract $contract, int $b24CompanyId, ?int $b24ContactId): array
    {
        $title = "Договор №{$contract->number}" . ($contract->date ? " от " . $contract->date->format('d.m.Y') : '');

        $fields = [
            'title' => $title,
            // Привязываем к Компании, которую нашли через реквизит
            'COMPANY_ID' => $b24CompanyId,

            $this->getFieldName('NUMBER')      => $contract->number,
            $this->getFieldName('DATE')        => $contract->date ? $contract->date->format('Y-m-d') : null,
            $this->getFieldName('BASIS')       => $contract->signer_basis,
            $this->getFieldName('GUID_1C')     => $contract->guid_1c,
            $this->getFieldName('IS_EDO')      => $contract->is_edo ? 'Y' : 'N',
            $this->getFieldName('IS_ANNULLED') => $contract->is_annulled ? 'Y' : 'N',
        ];

        if ($b24ContactId) {
            $fields['CONTACT_ID'] = $b24ContactId;
        }

        $b24ResponsibleId = null; // Инициализируем переменную
        // Предполагаем, что в модели Counterparty есть поле responsible_guid_1c,
        // которое хранит GUID ответственного менеджера в 1С.
        if ($contract->counterparty && !empty($contract->counterparty->responsible_guid_1c)) {
            $responsibleGuid =  $contract->counterparty->responsible_guid_1c;
            $b24ResponsibleId = $this->getResponsibleUserId($responsibleGuid);

            if (!$b24ResponsibleId) {
                Log::warning("Responsible user not found in B24 by GUID: {$responsibleGuid}. Contract will be synced with default responsible.");
            }
        }

        if ($b24ResponsibleId) {
            $fields['assignedById'] = $b24ResponsibleId;
        }

        return $fields;
    }


    // Хелпер для быстрого поиска ID пользователя по GUID
    protected function getResponsibleUserId(?string $guid1c): ?int
    {
        if (empty($guid1c)) return null;
        $cache = $this->getUsersCache();
        return $cache[$guid1c] ?? null;
    }

    protected function getUsersCache()
    {
        if ($this->usersCache === null) {
            $this->usersCache = [];
            // Используем константу для имени поля GUID пользователя
            $users = $this->b24Service->call('user.get', [
                'select' => ['ID', self::USER_GUID_FIELD],
                'filter' => ['ACTIVE' => 'Y'] // На всякий случай берем только активных
            ]);
            if (!empty($users['result'])) {
                foreach ($users['result'] as $user) {
                    if (!empty($user[self::USER_GUID_FIELD])) {
                        $this->usersCache[$user[self::USER_GUID_FIELD]] = (int)$user['ID'];
                    }
                }
            }
        }
        return $this->usersCache;
    }

    // =========================================================================
    // МЕТОДЫ ПОИСКА В Б24
    // =========================================================================

    protected function findB24ContractIdByGuid(string $guid): ?int
    {
        $guidFieldName = $this->getFieldName('GUID_1C');
        $response = $this->b24Service->call('crm.item.list', [
            'entityTypeId' => $this::SPA_ID,
            'filter' => [$guidFieldName => $guid],
            //'select' => ['id'],
            'limit' => 1,
            'useOriginalUfNames' => 'Y'
        ]);
        Log::debug("resp", ['data' => $response]);
        return !empty($response['result']['items'][0]['id']) ? (int)$response['result']['items'][0]['id'] : null;
    }

    /**
     * Ищет РЕКВИЗИТ по GUID и возвращает его данные, включая ID компании.
     * Восстановленная логика.
     */
    protected function findRequisiteByGuid(?string $guid): ?array
    {
        if (empty($guid)) return null;
        if (isset($this->requisiteMapCache[$guid])) return $this->requisiteMapCache[$guid];

        Log::debug("Searching Requisite by GUID", ['guid' => $guid]);

        $response = $this->b24Service->call('crm.requisite.list', [
            // Фильтруем по кастомному полю GUID в реквизите
            'filter' => [$this::REQUISITE_GUID_FIELD => $guid],
            // Нам нужен ID реквизита и ENTITY_ID (это ID Компании)
            'select' => ['ID', 'ENTITY_ID']
        ]);

        if (!empty($response['result'][0])) {
            $reqData = $response['result'][0];
            $this->requisiteMapCache[$guid] = $reqData;
            return $reqData;
        }

        return null;
    }

    protected function findB24ContactId(?string $guid): ?int
    {
        if (empty($guid)) return null;
        if (isset($this->contactMapCache[$guid])) return $this->contactMapCache[$guid];

        // Предполагаем, что имя поля GUID у контактов такое же. Если нет - замените константу.
        $contactGuidField = self::REQUISITE_GUID_FIELD;

        $response = $this->b24Service->call('crm.contact.list', [
            'filter' => [$contactGuidField => $guid],
            'select' => ['ID'],
            'limit' => 1
        ]);

        $id = !empty($response['result'][0]['ID']) ? (int)$response['result'][0]['ID'] : null;
        if ($id) {
            $this->contactMapCache[$guid] = $id;
        }
        return $id;
    }
}
