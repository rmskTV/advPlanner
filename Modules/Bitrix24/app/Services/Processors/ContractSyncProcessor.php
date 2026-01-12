<?php

// Modules/Bitrix24/app/Services/Processors/ContractSyncProcessor.php

namespace Modules\Bitrix24\app\Services\Processors;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ContactPerson;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Bitrix24\app\Exceptions\DependencyNotReadyException;
use Modules\Bitrix24\app\Exceptions\ValidationException;

class ContractSyncProcessor extends AbstractBitrix24Processor
{
    const SPA_ID = 1064;

    const SPA_FIELD_ID = 19;

    /**
     * @throws ValidationException
     * @throws DependencyNotReadyException
     */
    protected function syncEntity(ObjectChangeLog $change): void
    {
        $contract = Contract::find($change->local_id);

        if (! $contract) {
            throw new ValidationException("Contract not found: {$change->local_id}");
        }

        // Валидация зависимостей
        $this->validateDependencies($contract);

        Log::info('Processing contract', ['guid' => $contract->guid_1c, 'number' => $contract->number]);

        // Получаем зависимости
        $dependencies = $this->resolveDependencies($contract);

        // Подготавливаем поля
        $fields = $this->prepareContractFields($contract, $dependencies);

        // Ищем существующий договор
        $existingContractId = $this->findContractByGuid($contract->guid_1c);

        if ($existingContractId) {
            // UPDATE
            $this->updateContract($existingContractId, $fields);
            $change->b24_id = $existingContractId;
        } else {
            // CREATE
            $contractId = $this->createContract($fields);
            $change->b24_id = $contractId;
        }
    }

    /**
     * Валидация зависимостей
     */
    protected function validateDependencies(Contract $contract): void
    {
        if (empty($contract->guid_1c)) {
            throw new ValidationException("Contract {$contract->id} has no GUID");
        }

        if (empty($contract->counterparty_guid_1c)) {
            throw new ValidationException("Contract {$contract->guid_1c} has no counterparty_guid_1c");
        }
    }

    /**
     * Разрешение зависимостей
     */
    protected function resolveDependencies(Contract $contract): array
    {
        // Компания (обязательно)
        $companyId = $this->findCompanyIdByRequisiteGuid($contract->counterparty_guid_1c);

        if (! $companyId) {
            throw new DependencyNotReadyException(
                "Company not synced for requisite GUID: {$contract->counterparty_guid_1c}"
            );
        }

        // Реквизит (обязательно)
        $requisiteId = $this->findRequisiteByGuid($contract->counterparty_guid_1c)['ID'];

        if (!$requisiteId) {
            throw new DependencyNotReadyException(
                "Company not synced for requisite GUID: {$contract->counterparty_guid_1c}"
            );
        }

        // Контакт (опционально)
        $contactId = null;
        $localContact = ContactPerson::where('counterparty_guid_1c', $contract->counterparty_guid_1c)
            ->first();

        if ($localContact && $localContact->guid_1c) {
            $contactId = $this->findContactByGuid($localContact->guid_1c);
        }

        // Ответственный (опционально)
        $responsibleId = null;
        if ($contract->counterparty && $contract->counterparty->responsible_guid_1c) {
            $responsibleId = $this->findUserIdByGuid($contract->counterparty->responsible_guid_1c);
        }

        return [
            'company_id' => $companyId,
            'company_name' => $contract->counterparty->name,
            'requisite_id' => $requisiteId,
            'contact_id' => $contactId,
            'responsible_id' => $responsibleId,
        ];
    }

    /**
     * Подготовка полей договора
     */
    protected function prepareContractFields(Contract $contract, array $dependencies): array
    {
        $title = "Договор №{$contract->number}";
        if ($contract->date) {
            $title .= ' от '.$contract->date->format('d.m.Y');
        }

        $fields = [
            'title' => $title . ' c ' . $dependencies['company_name'],
            'COMPANY_ID' => $dependencies['company_id'],
            $this->getFieldName('ContractNo') => $contract->number,
            $this->getFieldName('ContractDate') => $contract->date?->format('Y-m-d'),
            $this->getFieldName('SignerBasis') => $this->cleanString($contract->signer_basis),
            'ufCrm_19_GUID_1C' => $contract->guid_1c,
            $this->getFieldName('RequisiteId') => $dependencies['requisite_id'],
            $this->getFieldName('IsEdo') => $contract->is_edo ? 'Y' : 'N',
            $this->getFieldName('IsAnnuled') => $contract->is_annulled ? 'Y' : 'N',
            'ufCrm_19_LAST_UPDATE_FROM_1C' => Carbon::now()->addSecond(2)->toIso8601String(),
        ];

        if ($dependencies['contact_id']) {
            $fields['CONTACT_ID'] = $dependencies['contact_id'];
        }

        if ($dependencies['responsible_id']) {
            $fields['assignedById'] = $dependencies['responsible_id'];
        }

        return array_filter($fields, fn ($value) => $value !== null);
    }

    /**
     * Создание договора
     */
    protected function createContract(array $fields): int
    {
        $result = $this->b24Service->call('crm.item.add', [
            'entityTypeId' => self::SPA_ID,
            'fields' => $fields,
            //'useOriginalUfNames' => 'Y',
        ]);

        if (empty($result['result']['item']['id'])) {
            throw new \Exception('Failed to create contract: '.json_encode($result));
        }

        $contractId = (int) $result['result']['item']['id'];

        Log::info('Contract created', ['b24_id' => $contractId]);

        return $contractId;
    }

    /**
     * Обновление договора
     */
    protected function updateContract(int $contractId, array $fields): void
    {
        $this->b24Service->call('crm.item.update', [
            'entityTypeId' => self::SPA_ID,
            'id' => $contractId,
            'fields' => $fields,
            //'useOriginalUfNames' => 'Y',
        ]);

        Log::debug('Contract updated', ['b24_id' => $contractId]);
    }

    /**
     * Поиск договора по GUID
     */
    protected function findContractByGuid(string $guid): ?int
    {
        $guidFieldName = 'ufCrm_19_GUID_1C';

        $response = $this->b24Service->call('crm.item.list', [
            'entityTypeId' => self::SPA_ID,
            'filter' => [$guidFieldName => $guid],
            'select' => ['id'],
            'limit' => 1,
            //'useOriginalUfNames' => 'Y',
        ]);
        Log::info($response['result']['items']);
        return $response['result']['items'][0]['id'] ?? null;
    }

    /**
     * Получение имени поля SPA
     */
    protected function getFieldName(string $suffix): string
    {
        return 'UfCRM'.self::SPA_FIELD_ID."{$suffix}";
    }
}
