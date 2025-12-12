<?php

namespace Modules\Bitrix24\app\Services;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Accounting\app\Models\Organization;

class OrganizationSyncProcessor
{
    protected Bitrix24Service $b24Service;
    protected RequisiteService $requisiteService;

    const REQUISITE_GUID_FIELD = 'UF_CRM_GUID_1C';

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
        $this->requisiteService = new RequisiteService($b24Service);
    }

    /**
     * Основной метод обработки
     */
    public function processOrganization(ObjectChangeLog $change): void
    {
        $organization = Organization::find($change->local_id);

        if (!$organization) {
            throw new \Exception("Organization not found: {$change->local_id}");
        }

        // Пропускаем организации без ИНН
        if (empty($organization->inn)) {
            $change->status = 'skipped';
            $change->error = 'Missing INN';
            $change->save();
            return;
        }

        Log::info("Processing Organization sync", [
            'id' => $organization->id,
            'guid' => $organization->guid_1c,
            'inn' => $organization->inn
        ]);

        // Шаг 1: Ищем реквизит по GUID
        $requisiteData = $this->findRequisiteByGuid($organization->guid_1c);

        if ($requisiteData) {
            // Найден реквизит → обновляем
            $this->updateExisting($requisiteData, $organization, $change);
            return;
        }

        // Шаг 2: Ищем "Мою компанию" по ИНН
        $myCompanyData = $this->findMyCompanyByInn($organization->inn);

        if ($myCompanyData) {
            // Найдена компания по ИНН → привязываем и обновляем
            $this->linkAndUpdate($myCompanyData, $organization, $change);
            return;
        }

        // Шаг 3: Ничего не найдено → создаём новую "Мою компанию"
        $this->createNew($organization, $change);
    }

    // =========================================================================
    // СТРАТЕГИИ ОБРАБОТКИ
    // =========================================================================

    /**
     * Обновление существующей структуры (найден реквизит по GUID)
     * @throws \Exception
     */
    protected function updateExisting(array $requisiteData, Organization $organization, ObjectChangeLog $change): void
    {
        $requisiteId = $requisiteData['ID'];
        $companyId = $requisiteData['ENTITY_ID'];

        Log::info("Found existing requisite by GUID, updating", [
            'requisite_id' => $requisiteId,
            'company_id' => $companyId
        ]);

        // Обновляем компанию-контейнер
        $this->updateMyCompany($companyId, $organization);

        // Обновляем реквизит
        $this->requisiteService->updateOrganizationRequisite($requisiteId, $organization);

        $change->b24_id = $requisiteId;
        $change->markProcessed();

        Log::info("Organization updated successfully", ['requisite_id' => $requisiteId]);
    }

    /**
     * Привязка к найденной по ИНН компании
     */
    protected function linkAndUpdate(array $myCompanyData, Organization $organization, ObjectChangeLog $change): void
    {
        $companyId = $myCompanyData['company_id'];
        $requisiteId = $myCompanyData['requisite_id'] ?? null;

        Log::info("Found My Company by INN, linking", [
            'company_id' => $companyId,
            'requisite_id' => $requisiteId
        ]);

        // Обновляем компанию
        $this->updateMyCompany($companyId, $organization);

        if ($requisiteId) {
            // Обновляем существующий реквизит и записываем туда GUID
            $this->requisiteService->updateOrganizationRequisite($requisiteId, $organization);
        } else {
            // Создаём реквизит (компания есть, а реквизита нет)
            $requisiteId = $this->requisiteService->createOrganizationRequisite($companyId, $organization);
        }

        $change->b24_id = $requisiteId;
        $change->markProcessed();

        Log::info("Organization linked and updated", ['requisite_id' => $requisiteId]);
    }

    /**
     * Создание новой "Моей компании"
     */
    protected function createNew(Organization $organization, ObjectChangeLog $change): void
    {
        Log::info("Creating new My Company", ['guid' => $organization->guid_1c]);

        // Создаём компанию с флагом IS_MY_COMPANY
        $companyId = $this->createMyCompany($organization);

        // Создаём реквизит
        $requisiteId = $this->requisiteService->createOrganizationRequisite($companyId, $organization);

        $change->b24_id = $requisiteId;
        $change->markProcessed();

        Log::info("Organization created successfully", [
            'company_id' => $companyId,
            'requisite_id' => $requisiteId
        ]);
    }

    // =========================================================================
    // ПОИСК
    // =========================================================================

    /**
     * Поиск реквизита по GUID
     */
    protected function findRequisiteByGuid(?string $guid): ?array
    {
        if (empty($guid)) {
            return null;
        }

        Log::debug("Searching requisite by GUID", ['guid' => $guid]);

        $response = $this->b24Service->call('crm.requisite.list', [
            'filter' => [self::REQUISITE_GUID_FIELD => $guid],
            'select' => ['ID', 'ENTITY_ID', 'ENTITY_TYPE_ID']
        ]);

        if (!empty($response['result'][0])) {
            return $response['result'][0];
        }

        return null;
    }

    /**
     * Поиск "Моей компании" по ИНН
     * Возвращает ['company_id' => ..., 'requisite_id' => ...] или null
     */
    protected function findMyCompanyByInn(string $inn): ?array
    {
        Log::debug("Searching My Company by INN", ['inn' => $inn]);

        // Шаг 1: Получаем все "Мои компании"
        $companies = $this->b24Service->call('crm.company.list', [
            'filter' => ['IS_MY_COMPANY' => 'Y'],
            'select' => ['ID', 'TITLE']
        ]);

        if (empty($companies['result'])) {
            return null;
        }

        // Шаг 2: Для каждой компании ищем реквизит с нужным ИНН
        foreach ($companies['result'] as $company) {
            $companyId = $company['ID'];

            $requisites = $this->b24Service->call('crm.requisite.list', [
                'filter' => [
                    'ENTITY_TYPE_ID' => 4, // Компания
                    'ENTITY_ID' => $companyId,
                    'RQ_INN' => $inn
                ],
                'select' => ['ID', 'RQ_INN']
            ]);

            if (!empty($requisites['result'][0])) {
                Log::info("Found My Company by INN", [
                    'company_id' => $companyId,
                    'requisite_id' => $requisites['result'][0]['ID']
                ]);

                return [
                    'company_id' => (int)$companyId,
                    'requisite_id' => (int)$requisites['result'][0]['ID']
                ];
            }

            // Проверяем, может компания есть, но без реквизита
            // (редкий случай, но возможен)
        }

        // Альтернатива: компания есть, но реквизита с ИНН нет
        // Можно расширить логику при необходимости

        return null;
    }

    // =========================================================================
    // CRUD ОПЕРАЦИИ
    // =========================================================================

    /**
     * Создание "Моей компании"
     */
    protected function createMyCompany(Organization $organization): int
    {
        $fields = $this->prepareCompanyFields($organization);
        $fields['IS_MY_COMPANY'] = 'Y'; // Ключевой флаг!

        $result = $this->b24Service->call('crm.company.add', [
            'fields' => $fields
        ]);

        if (empty($result['result'])) {
            throw new \Exception("Failed to create My Company: " . json_encode($result));
        }

        Log::info("My Company created", ['b24_id' => $result['result']]);

        return (int)$result['result'];
    }

    /**
     * Обновление "Моей компании"
     */
    protected function updateMyCompany(int $companyId, Organization $organization): void
    {
        $fields = $this->prepareCompanyFields($organization);

        $this->b24Service->call('crm.company.update', [
            'id' => $companyId,
            'fields' => $fields
        ]);

        Log::debug("My Company updated", ['b24_id' => $companyId]);
    }

    /**
     * Подготовка полей компании
     */
    protected function prepareCompanyFields(Organization $organization): array
    {
        $title = $this->cleanString($organization->name);

        $fields = [
            'TITLE' => $title,
            'COMPANY_TYPE' => 'SELF', // Тип "Своя компания"
        ];

        // Контактные данные
        if ($organization->phone) {
            $fields['PHONE'] = [['VALUE' => $organization->phone, 'VALUE_TYPE' => 'WORK']];
        }

        if ($organization->email) {
            $fields['EMAIL'] = [['VALUE' => $organization->email, 'VALUE_TYPE' => 'WORK']];
        }

        if ($organization->website) {
            $fields['WEB'] = [['VALUE' => $organization->website, 'VALUE_TYPE' => 'WORK']];
        }

        return $fields;
    }

    /**
     * Очистка строки
     */
    protected function cleanString(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
