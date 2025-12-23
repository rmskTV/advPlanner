<?php
// Modules/Bitrix24/app/Services/Processors/CompanySyncProcessor.php

namespace Modules\Bitrix24\app\Services\Processors;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Bitrix24\app\Enums\Bitrix24EntityType;
use Modules\Bitrix24\app\Enums\Bitrix24FieldType;
use Modules\Bitrix24\app\Exceptions\ValidationException;
use Modules\Bitrix24\app\Services\RequisiteService;

class CompanySyncProcessor extends AbstractBitrix24Processor
{
    protected RequisiteService $requisiteService;

    public function __construct($b24Service)
    {
        parent::__construct($b24Service);
        $this->requisiteService = new RequisiteService($b24Service);
    }

    protected function syncEntity(ObjectChangeLog $change): void
    {
        $counterparty = Counterparty::find($change->local_id);

        if (!$counterparty) {
            throw new ValidationException("Counterparty not found: {$change->local_id}");
        }

        // Валидация
        $this->validateCounterparty($counterparty);

        Log::info("Processing counterparty", ['guid' => $counterparty->guid_1c, 'inn' => $counterparty->inn]);

        // Ищем существующий реквизит
        $existingRequisite = $this->findRequisiteByGuid($counterparty->guid_1c);

        if ($existingRequisite) {
            // UPDATE: реквизит найден
            $this->updateExisting($existingRequisite, $counterparty, $change);
        } else {
            // CREATE: создаём новую структуру
            $this->createNew($counterparty, $change);
        }
    }

    /**
     * Валидация контрагента
     */
    protected function validateCounterparty(Counterparty $counterparty): void
    {
        if (empty($counterparty->inn)) {
            throw new ValidationException("Missing INN for counterparty {$counterparty->id}");
        }

        $innLength = strlen($counterparty->inn);
        if (!in_array($innLength, [10, 12])) {
            throw new ValidationException("Invalid INN length: {$innLength}");
        }
    }

    /**
     * Обновление существующей структуры
     */
    protected function updateExisting(array $requisiteData, Counterparty $counterparty, ObjectChangeLog $change): void
    {
        $requisiteId = (int)$requisiteData['ID'];
        $companyId = (int)$requisiteData['ENTITY_ID'];

        Log::debug("Updating existing company/requisite", [
            'company_id' => $companyId,
            'requisite_id' => $requisiteId
        ]);

        // Обновляем компанию-контейнер
        $this->updateCompany($companyId, $counterparty);

        // Обновляем реквизит
        $this->requisiteService->updateCompanyRequisite($requisiteId, $counterparty);

        // Сбрасываем кэш
        $this->invalidateCache('requisite', $counterparty->guid_1c);

        $change->b24_id = $requisiteId;
    }

    /**
     * Создание новой структуры
     */
    protected function createNew(Counterparty $counterparty, ObjectChangeLog $change): void
    {
        Log::debug("Creating new company/requisite");

        // Создаём компанию-контейнер
        $companyId = $this->createCompany($counterparty);

        // Создаём реквизит
        $requisiteId = $this->requisiteService->createCompanyRequisite($companyId, $counterparty);

        // Сбрасываем кэш
        $this->invalidateCache('requisite', $counterparty->guid_1c);

        $change->b24_id = $requisiteId;
    }

    /**
     * Создание компании-контейнера
     */
    protected function createCompany(Counterparty $counterparty): int
    {
        $fields = $this->prepareCompanyFields($counterparty);

        $result = $this->b24Service->call('crm.company.add', [
            'fields' => $fields
        ]);

        if (empty($result['result'])) {
            throw new \Exception("Failed to create company: " . json_encode($result));
        }

        $companyId = (int)$result['result'];

        Log::info("Company created", ['b24_id' => $companyId]);

        return $companyId;
    }

    /**
     * Обновление компании-контейнера
     */
    protected function updateCompany(int $companyId, Counterparty $counterparty): void
    {
        $fields = $this->prepareCompanyFields($counterparty);

        $this->b24Service->call('crm.company.update', [
            'id' => $companyId,
            'fields' => $fields
        ]);

        Log::debug("Company updated", ['b24_id' => $companyId]);
    }

    /**
     * Подготовка полей компании
     */
    protected function prepareCompanyFields(Counterparty $counterparty): array
    {
        $fields = [
            'TITLE' => $this->cleanString($counterparty->name),
            'COMPANY_TYPE' => 'CUSTOMER',
            'COMMENTS' => $this->cleanString($counterparty->description) ?? '',
        ];

        // Ответственный
        if ($counterparty->responsible_guid_1c) {
            $responsibleId = $this->findUserIdByGuid($counterparty->responsible_guid_1c);
            if ($responsibleId) {
                $fields['ASSIGNED_BY_ID'] = $responsibleId;
            }
        }

        // Телефон
        if ($counterparty->phone) {
            $fields['PHONE'] = [['VALUE' => $counterparty->phone, 'VALUE_TYPE' => 'WORK']];
        }

        // Email
        if ($counterparty->email) {
            $fields['EMAIL'] = [['VALUE' => $counterparty->email, 'VALUE_TYPE' => 'WORK']];
        }

        return $fields;
    }
}
