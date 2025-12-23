<?php

// Modules/Bitrix24/app/Services/Processors/ContactSyncProcessor.php

namespace Modules\Bitrix24\app\Services\Processors;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ContactPerson;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Bitrix24\app\Enums\Bitrix24FieldType;
use Modules\Bitrix24\app\Exceptions\DependencyNotReadyException;
use Modules\Bitrix24\app\Exceptions\ValidationException;

class ContactSyncProcessor extends AbstractBitrix24Processor
{
    protected function syncEntity(ObjectChangeLog $change): void
    {
        $contact = ContactPerson::find($change->local_id);

        if (! $contact) {
            throw new ValidationException("Contact not found: {$change->local_id}");
        }

        // Валидация зависимостей
        $this->validateDependencies($contact);

        Log::info('Processing contact', ['guid' => $contact->guid_1c]);

        // Ищем существующий контакт
        $existingContactId = $this->findContactByGuid($contact->guid_1c);

        if ($existingContactId) {
            // UPDATE
            $this->updateContact($existingContactId, $contact, $change);
        } else {
            // CREATE
            $this->createContact($contact, $change);
        }
    }

    /**
     * Валидация зависимостей
     */
    protected function validateDependencies(ContactPerson $contact): void
    {
        if (empty($contact->counterparty_guid_1c)) {
            throw new ValidationException("Contact {$contact->id} has no parent company GUID");
        }

        // Проверяем, что родительская компания существует
        $counterparty = Counterparty::where('guid_1c', $contact->counterparty_guid_1c)->first();

        if (! $counterparty || empty($counterparty->inn)) {
            throw new ValidationException("Parent company not viable for contact {$contact->id}");
        }
    }

    /**
     * Получение ID компании-родителя
     */
    protected function getParentCompanyId(ContactPerson $contact): int
    {
        $companyId = $this->findCompanyIdByRequisiteGuid($contact->counterparty_guid_1c);

        if (! $companyId) {
            throw new DependencyNotReadyException(
                "Parent company not synced yet for GUID: {$contact->counterparty_guid_1c}"
            );
        }

        return $companyId;
    }

    /**
     * Создание контакта
     */
    protected function createContact(ContactPerson $contact, ObjectChangeLog $change): void
    {
        $companyId = $this->getParentCompanyId($contact);
        $fields = $this->prepareContactFields($contact, $companyId);

        $result = $this->b24Service->call('crm.contact.add', [
            'fields' => $fields,
        ]);

        if (empty($result['result'])) {
            throw new \Exception('Failed to create contact: '.json_encode($result));
        }

        $contactId = (int) $result['result'];

        // Сбрасываем кэш
        $this->invalidateCache('contact', $contact->guid_1c);

        $change->b24_id = $contactId;

        Log::info('Contact created', ['b24_id' => $contactId]);
    }

    /**
     * Обновление контакта
     */
    protected function updateContact(int $contactId, ContactPerson $contact, ObjectChangeLog $change): void
    {
        $fields = $this->prepareContactFields($contact);

        // Проверяем привязку к компании
        $currentContact = $this->b24Service->call('crm.contact.get', ['id' => $contactId]);

        if (empty($currentContact['result']['COMPANY_ID']) && $contact->counterparty_guid_1c) {
            $companyId = $this->getParentCompanyId($contact);
            $fields['COMPANY_ID'] = $companyId;
        }

        $this->b24Service->call('crm.contact.update', [
            'id' => $contactId,
            'fields' => $fields,
        ]);

        // Сбрасываем кэш
        $this->invalidateCache('contact', $contact->guid_1c);

        $change->b24_id = $contactId;

        Log::debug('Contact updated', ['b24_id' => $contactId]);
    }

    /**
     * Подготовка полей контакта
     */
    protected function prepareContactFields(ContactPerson $contact, ?int $companyId = null): array
    {
        $fields = [
            'NAME' => $this->cleanString($contact->first_name),
            'LAST_NAME' => $this->cleanString($contact->last_name),
            'SECOND_NAME' => $this->cleanString($contact->middle_name),
            'POST' => $this->cleanString($contact->position),
            Bitrix24FieldType::CONTACT_GUID->value => $contact->guid_1c,
        ];

        if ($companyId) {
            $fields['COMPANY_ID'] = $companyId;
        }

        // Ответственный (берём от контрагента)
        if ($contact->counterparty && $contact->counterparty->responsible_guid_1c) {
            $responsibleId = $this->findUserIdByGuid($contact->counterparty->responsible_guid_1c);
            if ($responsibleId) {
                $fields['ASSIGNED_BY_ID'] = $responsibleId;
            }
        }

        // Телефон
        if ($contact->phone) {
            $fields['PHONE'] = [['VALUE' => $contact->phone, 'VALUE_TYPE' => 'WORK']];
        }

        // Email
        if ($contact->email) {
            $fields['EMAIL'] = [['VALUE' => $contact->email, 'VALUE_TYPE' => 'WORK']];
        }

        return $fields;
    }
}
