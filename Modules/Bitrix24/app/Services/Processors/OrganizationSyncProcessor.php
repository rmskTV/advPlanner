<?php
// Modules/Bitrix24/app/Services/Processors/OrganizationSyncProcessor.php

namespace Modules\Bitrix24\app\Services\Processors;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Accounting\app\Models\Organization;
use Modules\Bitrix24\app\Enums\Bitrix24FieldType;
use Modules\Bitrix24\app\Exceptions\ValidationException;
use Modules\Bitrix24\app\Services\RequisiteService;

class OrganizationSyncProcessor extends AbstractBitrix24Processor
{
    protected RequisiteService $requisiteService;

    public function __construct($b24Service)
    {
        parent::__construct($b24Service);
        $this->requisiteService = new RequisiteService($b24Service);
    }

    protected function syncEntity(ObjectChangeLog $change): void
    {
        $organization = Organization::find($change->local_id);

        if (!$organization) {
            throw new ValidationException("Organization not found: {$change->local_id}");
        }

        // Валидация
        $this->validateOrganization($organization);

        Log::info("Processing organization", ['guid' => $organization->guid_1c, 'inn' => $organization->inn]);

        // Ищем реквизит по GUID
        $requisiteData = $this->findRequisiteByGuid($organization->guid_1c);

        if ($requisiteData) {
            // UPDATE: реквизит найден
            $this->updateExisting($requisiteData, $organization, $change);
        } else {
            // Ищем "Мою компанию" по ИНН
            $myCompanyData = $this->findMyCompanyByInn($organization->inn);

            if ($myCompanyData) {
                // LINK: привязываемся к найденной компании
                $this->linkAndUpdate($myCompanyData, $organization, $change);
            } else {
                // CREATE: создаём новую "Мою компанию"
                $this->createNew($organization, $change);
            }
        }
    }

    /**
     * Валидация организации
     */
    protected function validateOrganization(Organization $organization): void
    {
        if (empty($organization->inn)) {
            throw new ValidationException("Missing INN for organization {$organization->id}");
        }
    }

    /**
     * Обновление существующей структуры
     */
    protected function updateExisting(array $requisiteData, Organization $organization, ObjectChangeLog $change): void
    {
        $requisiteId = (int)$requisiteData['ID'];
        $companyId = (int)$requisiteData['ENTITY_ID'];

        Log::debug("Updating existing My Company", [
            'company_id' => $companyId,
            'requisite_id' => $requisiteId
        ]);

        // Обновляем компанию
        $this->updateMyCompany($companyId, $organization);

        // Обновляем реквизит
        $this->requisiteService->updateOrganizationRequisite($requisiteId, $organization);

        // Сбрасываем кэш
        $this->invalidateCache('requisite', $organization->guid_1c);

        $change->b24_id = $requisiteId;
    }

    /**
     * Привязка к найденной компании
     */
    protected function linkAndUpdate(array $myCompanyData, Organization $organization, ObjectChangeLog $change): void
    {
        $companyId = (int)$myCompanyData['company_id'];
        $requisiteId = $myCompanyData['requisite_id'] ?? null;

        Log::debug("Linking to existing My Company", [
            'company_id' => $companyId,
            'requisite_id' => $requisiteId
        ]);

        // Обновляем компанию
        $this->updateMyCompany($companyId, $organization);

        if ($requisiteId) {
            // Обновляем существующий реквизит
            $this->requisiteService->updateOrganizationRequisite($requisiteId, $organization);
        } else {
            // Создаём реквизит
            $requisiteId = $this->requisiteService->createOrganizationRequisite($companyId, $organization);
        }

        // Сбрасываем кэш
        $this->invalidateCache('requisite', $organization->guid_1c);

        $change->b24_id = $requisiteId;
    }

    /**
     * Создание новой "Моей компании"
     */
    protected function createNew(Organization $organization, ObjectChangeLog $change): void
    {
        Log::debug("Creating new My Company");

        // Создаём компанию
        $companyId = $this->createMyCompany($organization);

        // Создаём реквизит
        $requisiteId = $this->requisiteService->createOrganizationRequisite($companyId, $organization);

        // Сбрасываем кэш
        $this->invalidateCache('requisite', $organization->guid_1c);

        $change->b24_id = $requisiteId;
    }

    /**
     * Создание "Моей компании"
     */
    protected function createMyCompany(Organization $organization): int
    {
        $fields = $this->prepareMyCompanyFields($organization);
        $fields['IS_MY_COMPANY'] = 'Y';

        $result = $this->b24Service->call('crm.company.add', [
            'fields' => $fields
        ]);

        if (empty($result['result'])) {
            throw new \Exception("Failed to create My Company: " . json_encode($result));
        }

        $companyId = (int)$result['result'];

        Log::info("My Company created", ['b24_id' => $companyId]);

        return $companyId;
    }

    /**
     * Обновление "Моей компании"
     */
    protected function updateMyCompany(int $companyId, Organization $organization): void
    {
        $fields = $this->prepareMyCompanyFields($organization);

        $this->b24Service->call('crm.company.update', [
            'id' => $companyId,
            'fields' => $fields
        ]);

        Log::debug("My Company updated", ['b24_id' => $companyId]);
    }

    /**
     * Подготовка полей "Моей компании"
     */
    protected function prepareMyCompanyFields(Organization $organization): array
    {
        $fields = [
            'TITLE' => $this->cleanString($organization->name),
            'COMPANY_TYPE' => 'SELF',
        ];

        // Телефон
        if ($organization->phone) {
            $fields['PHONE'] = [['VALUE' => $organization->phone, 'VALUE_TYPE' => 'WORK']];
        }

        // Email
        if ($organization->email) {
            $fields['EMAIL'] = [['VALUE' => $organization->email, 'VALUE_TYPE' => 'WORK']];
        }

        // Сайт
        if ($organization->website) {
            $fields['WEB'] = [['VALUE' => $organization->website, 'VALUE_TYPE' => 'WORK']];
        }

        return $fields;
    }

    /**
     * Поиск "Моей компании" по ИНН
     */
    protected function findMyCompanyByInn(string $inn): ?array
    {
        // Получаем все "Мои компании"
        $companies = $this->b24Service->call('crm.company.list', [
            'filter' => ['IS_MY_COMPANY' => 'Y'],
            'select' => ['ID', 'TITLE']
        ]);

        if (empty($companies['result'])) {
            return null;
        }

        // Для каждой компании ищем реквизит с нужным ИНН
        foreach ($companies['result'] as $company) {
            $companyId = (int)$company['ID'];

            $requisites = $this->b24Service->call('crm.requisite.list', [
                'filter' => [
                    'ENTITY_TYPE_ID' => 4,
                    'ENTITY_ID' => $companyId,
                    'RQ_INN' => $inn
                ],
                'select' => ['ID', 'RQ_INN']
            ]);

            if (!empty($requisites['result'][0])) {
                return [
                    'company_id' => $companyId,
                    'requisite_id' => (int)$requisites['result'][0]['ID']
                ];
            }
        }

        return null;
    }
}
