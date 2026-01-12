<?php

namespace Modules\Bitrix24\app\Services\Pull;

use Modules\Accounting\app\Models\ContactPerson;
use Modules\Bitrix24\app\Models\B24SyncState;
use Modules\Bitrix24\app\Services\Mappers\B24ContactMapper;

class ContactPuller extends AbstractPuller
{
    protected function getEntityType(): string
    {
        return B24SyncState::ENTITY_CONTACT;
    }

    protected function getB24Method(): string
    {
        return 'crm.contact';
    }

    protected function getSelectFields(): array
    {
        return [
            'ID',
            'NAME',
            'LAST_NAME',
            'SECOND_NAME',
            'POST',              // Должность
            'COMMENTS',
            'DATE_CREATE',
            'DATE_MODIFY',
            'ASSIGNED_BY_ID',    // Ответственный

            // Связь с компанией
            'COMPANY_ID',        // ID компании в B24

            // Контакты
            'PHONE',
            'EMAIL',

            // Кастомные поля
            'UF_CRM_GUID_1C',
            'UF_CRM_LAST_UPDATE_FROM_1C',
        ];
    }

    protected function getGuid1CFieldName(): string
    {
        return 'UF_CRM_GUID_1C';
    }

    protected function getLastUpdateFrom1CFieldName(): string
    {
        return 'UF_CRM_LAST_UPDATE_FROM_1C';
    }

    protected function mapToLocal(array $b24Item): array
    {
        $mapper = new B24ContactMapper($this->b24Service);
        return $mapper->map($b24Item);
    }

    protected function findOrCreateLocal(int $b24Id)
    {
        return ContactPerson::firstOrNew(['b24_id' => $b24Id]);
    }
}
