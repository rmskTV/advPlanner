<?php

namespace Modules\Bitrix24\app\Services;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\ContactPerson;

class CRMSyncProcessor
{
    protected $b24Service;
    protected $usersCache = null;
    protected $contactFieldsCache = null;
    protected $requisiteService;

    // Имена кастомных полей (должны совпадать с созданными в Битрикс24)
    const REQUISITE_GUID_FIELD = 'UF_CRM_GUID_1C'; // Поле в реквизите
    const CONTACT_GUID_FIELD = 'UF_CRM_GUID_1C';   // Поле в контакте

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
        // Инициализируем сервис реквизитов
        $this->requisiteService = new RequisiteService($b24Service);
    }

    // =========================================================================
    // КЭШИРОВАНИЕ И ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // =========================================================================

    protected function getContactFieldIds()
    {
        if ($this->contactFieldsCache === null) {
            $this->contactFieldsCache = [];
            $fields = $this->b24Service->call('crm.contact.userfield.list');
            if (!empty($fields['result'])) {
                foreach ($fields['result'] as $field) {
                    $this->contactFieldsCache[$field['FIELD_NAME']] = $field['ID'];
                }
            }
        }
        return $this->contactFieldsCache;
    }

    protected function getUsersCache()
    {
        if ($this->usersCache === null) {
            $this->usersCache = [];
            $users = $this->b24Service->call('user.get', ['select' => ['ID', 'UF_USR_1C_GUID']]);
            if (!empty($users['result'])) {
                foreach ($users['result'] as $user) {
                    if (!empty($user['UF_USR_1C_GUID'])) {
                        $this->usersCache[$user['UF_USR_1C_GUID']] = $user['ID'];
                    }
                }
            }
        }
        return $this->usersCache;
    }

    protected function getResponsibleUserId($guid1c)
    {
        if (empty($guid1c)) return null;
        $cache = $this->getUsersCache();
        return $cache[$guid1c] ?? null;
    }

    // =========================================================================
    // ПОИСК
    // =========================================================================

    /**
     * НОВЫЙ МЕТОД: Поиск РЕКВИЗИТА по GUID.
     * Возвращает массив ['ID' => id_реквизита, 'ENTITY_ID' => id_компании] или null.
     */
    protected function findRequisiteByGuid($guid)
    {
        if (empty($guid)) return null;

        Log::info("Searching REQUISITE by GUID", ['guid' => $guid]);

        // Фильтруем по кастомному полю реквизита
        $filter = [
            self::REQUISITE_GUID_FIELD => $guid
        ];

        // Нам нужен ID реквизита и ID компании (ENTITY_ID), к которой он привязан
        $requisites = $this->b24Service->call('crm.requisite.list', [
            'filter' => $filter,
            'select' => ['ID', 'ENTITY_ID']
        ]);

        if (!empty($requisites['result'][0])) {
            $reqData = $requisites['result'][0];
            Log::info("Requisite found", [
                'guid' => $guid,
                'requisite_id' => $reqData['ID'],
                'company_id' => $reqData['ENTITY_ID']
            ]);
            return $reqData;
        }

        Log::info("Requisite not found by GUID", ['guid' => $guid]);
        return null;
    }

    /**
     * Поиск контакта по GUID (использует новое имя поля)
     */
    protected function findContactByGuid($guid)
    {
        if (empty($guid)) return null;
        $fieldIds = $this->getContactFieldIds();

        if (!isset($fieldIds[self::CONTACT_GUID_FIELD])) {
            Log::error("Field " . self::CONTACT_GUID_FIELD . " not found for contacts");
            return null;
        }

        Log::info("Searching contact by GUID", ['guid' => $guid]);
        $filter = [self::CONTACT_GUID_FIELD => $guid];

        $contacts = $this->b24Service->call('crm.contact.list', [
            'filter' => $filter,
            'select' => ['ID']
        ]);

        return !empty($contacts['result'][0]) ? $contacts['result'][0]['ID'] : null;
    }


    // =========================================================================
    // ОБРАБОТКА КОМПАНИЙ (КОНТРАГЕНТОВ)
    // =========================================================================

    /**
     * @throws \Exception
     */
    public function processCompany(ObjectChangeLog $change)
    {
        $counterparty = Counterparty::find($change->local_id);
        if (!$counterparty) throw new \Exception("Counterparty not found: {$change->local_id}");

        if (empty($counterparty->inn)) {
            $change->status = 'skipped'; $change->error = 'Missing INN'; $change->save(); return;
        }

        Log::info("Processing counterparty via Requisite logic", ['guid' => $counterparty->guid_1c]);

        // 1. Ищем существующий РЕКВИЗИТ по GUID
        $existingRequisiteData = $this->findRequisiteByGuid($counterparty->guid_1c);

        if ($existingRequisiteData) {
            // А) Реквизит найден -> Обновляем его
            $requisiteId = $existingRequisiteData['ID'];
            $companyId = $existingRequisiteData['ENTITY_ID'];

            Log::info("Found existing requisite, updating", ['req_id' => $requisiteId, 'company_id' => $companyId]);

            // Обновляем "Компанию-контейнер"
            $this->updateContainerCompany($companyId, $counterparty);

            // Обновляем конкретный реквизит
            $this->requisiteService->updateCompanyRequisite($requisiteId, $counterparty);

            // --- ИЗМЕНЕНИЕ ЗДЕСЬ ---
            // Сохраняем ID реквизита, а не компании
            $change->b24_id = $requisiteId;
            $change->markProcessed();

        } else {
            // Б) Реквизит не найден -> Создаем новую структуру
            Log::info("Requisite not found, creating new Company+Requisite");

            // Создаем "Компанию-контейнер"
            $companyId = $this->createContainerCompany($counterparty);

            // --- ИЗМЕНЕНИЕ ЗДЕСЬ ---
            // Создаем реквизит и получаем его ID
            $requisiteId = $this->requisiteService->createCompanyRequisite($companyId, $counterparty);

            // Сохраняем ID реквизита, а не компании
            $change->b24_id = $requisiteId;
            $change->markProcessed();
        }
    }
    // =========================================================================
    // ОБРАБОТКА КОНТАКТОВ
    // =========================================================================

    public function processContact(ObjectChangeLog $change)
    {
        $contact = ContactPerson::find($change->local_id);
        if (!$contact) throw new \Exception("Contact not found: {$change->local_id}");

        // 1. Ищем контакт в Б24
        $existingContactId = $this->findContactByGuid($contact->guid_1c);

        if ($existingContactId) {
            return $this->updateContact($existingContactId, $contact, $change);
        }

        // 2. Проверки родителя
        if (empty($contact->counterparty_guid_1c)) {
            $change->status = 'skipped'; $change->error = 'No parent company GUID'; $change->save(); return;
        }
        $counterparty = Counterparty::where('guid_1c', $contact->counterparty_guid_1c)->first();
        if (!$counterparty || empty($counterparty->inn)) {
            $change->status = 'skipped'; $change->error = 'Parent company not viable'; $change->save(); return;
        }

        // 3. ВАЖНО: Получаем ID компании через поиск РЕКВИЗИТА родителя
        $companyId = $this->ensureCompanyExistsViaRequisite($counterparty);

        // 4. Создаем контакт
        return $this->createContact($contact, $companyId, $change);
    }


    // =========================================================================
    // ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ (CRUD)
    // =========================================================================

    /**
     * НОВЫЙ МЕТОД: Обеспечивает наличие компании, ищет ее через реквизит.
     */
    protected function ensureCompanyExistsViaRequisite(Counterparty $counterparty)
    {
        $existingRequisiteData = $this->findRequisiteByGuid($counterparty->guid_1c);

        if ($existingRequisiteData) {
            return $existingRequisiteData['ENTITY_ID'];
        }

        // Если не найдена, создаем компанию и реквизит
        $companyId = $this->createContainerCompany($counterparty);
        $this->requisiteService->createCompanyRequisite($companyId, $counterparty);
        return $companyId;
    }

    /**
     * Создание "Компании-контейнера". GUID сюда БОЛЬШЕ НЕ пишется.
     */
    protected function createContainerCompany(Counterparty $counterparty)
    {
        $assignedById = $this->getResponsibleUserId($counterparty->responsible_guid_1c);

        $b24Fields = [
            'TITLE' => trim(html_entity_decode($counterparty->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')), // Используем имя из 1С как название контейнера
            'COMPANY_TYPE' => 'CUSTOMER',
            'COMMENTS' => $counterparty->description ?? '',
            // 'UF_CRM_GUID_1C' => ... // УБРАНО!
        ];

        if ($assignedById) $b24Fields['ASSIGNED_BY_ID'] = $assignedById;
        // Добавляем телефон/email в саму компанию для удобства
        if ($counterparty->phone) $b24Fields['PHONE'] = [['VALUE' => $counterparty->phone, 'VALUE_TYPE' => 'WORK']];
        if ($counterparty->email) $b24Fields['EMAIL'] = [['VALUE' => $counterparty->email, 'VALUE_TYPE' => 'WORK']];

        $result = $this->b24Service->call('crm.company.add', ['fields' => $b24Fields]);
        Log::info("Created container company", ['b24_id' => $result['result']]);
        return $result['result'];
    }

    /**
     * Обновление "Компании-контейнера". GUID не трогаем.
     */
    protected function updateContainerCompany($companyId, Counterparty $counterparty)
    {
        $assignedById = $this->getResponsibleUserId($counterparty->responsible_guid_1c);
        $b24Fields = [
            'TITLE' => trim(html_entity_decode($counterparty->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'COMMENTS' => $counterparty->description ?? '',
        ];
        if ($assignedById) $b24Fields['ASSIGNED_BY_ID'] = $assignedById;
        // Обновление телефонов/email в компании опущено для краткости, но можно добавить.

        $this->b24Service->call('crm.company.update', ['id' => $companyId, 'fields' => $b24Fields]);
        Log::info("Updated container company", ['b24_id' => $companyId]);
    }

    // Методы createContact и updateContact остаются практически без изменений,
    // кроме использования константы self::CONTACT_GUID_FIELD.

    protected function createContact(ContactPerson $contact, $companyId, ObjectChangeLog $change)
    {
        $b24Fields = [
            'NAME' => $contact->first_name,
            'LAST_NAME' => $contact->last_name,
            'SECOND_NAME' => $contact->middle_name,
            'POST' => $contact->position,
            'COMPANY_ID' => $companyId,
            self::CONTACT_GUID_FIELD => $contact->guid_1c // Используем константу
        ];
        $assignedById = $this->getResponsibleUserId($contact->counterparty->responsible_guid_1c);
        if ($assignedById) $b24Fields['ASSIGNED_BY_ID'] = $assignedById;

        if ($contact->phone) $b24Fields['PHONE'] = [['VALUE' => $contact->phone, 'VALUE_TYPE' => 'WORK']];
        if ($contact->email) $b24Fields['EMAIL'] = [['VALUE' => $contact->email, 'VALUE_TYPE' => 'WORK']];

        $result = $this->b24Service->call('crm.contact.add', ['fields' => $b24Fields]);
        $contactId = $result['result'];
        $change->b24_id = $contactId;
        $change->markProcessed();
        Log::info("Contact created", ['b24_id' => $contactId]);
        return $contactId;
    }

    protected function updateContact($contactId, ContactPerson $contact, ObjectChangeLog $change)
    {
        $b24Fields = [
            'NAME' => $contact->first_name,
            'LAST_NAME' => $contact->last_name,
            'SECOND_NAME' => $contact->middle_name,
            'POST' => $contact->position,
            self::CONTACT_GUID_FIELD => $contact->guid_1c
        ];
        $assignedById = $this->getResponsibleUserId($contact->counterparty->responsible_guid_1c);
        if ($assignedById) $b24Fields['ASSIGNED_BY_ID'] = $assignedById;

        // Логика проверки привязки к компании
        $currentContact = $this->b24Service->call('crm.contact.get', ['id' => $contactId]);
        if (empty($currentContact['result']['COMPANY_ID']) && $contact->counterparty_guid_1c) {
            $counterparty = Counterparty::where('guid_1c', $contact->counterparty_guid_1c)->first();
            if ($counterparty) {
                // Используем новый метод поиска через реквизит
                $companyId = $this->ensureCompanyExistsViaRequisite($counterparty);
                if ($companyId) $b24Fields['COMPANY_ID'] = $companyId;
            }
        }
        // ... обновление телефонов/email ...
        if ($contact->phone) $b24Fields['PHONE'] = [['VALUE' => $contact->phone, 'VALUE_TYPE' => 'WORK']];
        if ($contact->email) $b24Fields['EMAIL'] = [['VALUE' => $contact->email, 'VALUE_TYPE' => 'WORK']];

        $this->b24Service->call('crm.contact.update', ['id' => $contactId, 'fields' => $b24Fields]);
        $change->b24_id = $contactId;
        $change->markProcessed();
        Log::info("Contact updated", ['b24_id' => $contactId]);
        return $contactId;
    }
}
