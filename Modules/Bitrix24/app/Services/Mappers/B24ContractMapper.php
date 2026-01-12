<?php

namespace Modules\Bitrix24\app\Services\Mappers;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Bitrix24\app\Exceptions\DependencyNotReadyException;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class B24ContractMapper
{
    protected Bitrix24Service $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * Маппинг договора B24 → Contract
     */
    public function map(array $b24Contract): array
    {
        $data = [
            'name' => $this->cleanString($b24Contract['title'] ?? null),
            'number' => $this->cleanString($b24Contract['ufCrm19ContractNo'] ?? null),
            'date' => $this->parseDate($b24Contract['ufCrm19ContractDate'] ?? null),
            'signer_basis' => $this->cleanString($b24Contract['ufCrm_19_BASIS'] ?? null),
            'is_edo' => $this->parseBoolean($b24Contract['ufCrm_19_IS_EDO'] ?? null),
            'is_annulled' => $this->parseBoolean($b24Contract['ufCrm_19_IS_ANNULLED'] ?? null),
        ];

        // Связь с контрагентом через companyId (ОБЯЗАТЕЛЬНА!)
        if (!empty($b24Contract['companyId'])) {
            $counterpartyGuid = $this->findCounterpartyGuidByCompanyId($b24Contract['companyId']);

            if (!$counterpartyGuid) {
                throw new DependencyNotReadyException(
                    "Counterparty not synced yet for company ID: {$b24Contract['companyId']}"
                );
            }

            $data['counterparty_guid_1c'] = $counterpartyGuid;
        } else {
            // Договор без компании - пропускаем
            throw new DependencyNotReadyException(
                "Contract has no companyId: {$b24Contract['id']}"
            );
        }

        return $data;
    }

    /**
     * Найти GUID контрагента по ID компании B24
     */
    protected function findCounterpartyGuidByCompanyId(int $companyId): ?string
    {
        try {
            // Ищем реквизит компании
            $response = $this->b24Service->call('crm.requisite.list', [
                'filter' => [
                    'ENTITY_TYPE_ID' => 4,
                    'ENTITY_ID' => $companyId,
                ],
                'select' => ['ID', 'UF_CRM_GUID_1C'],
                'limit' => 1,
            ]);

            if (empty($response['result'][0])) {
                Log::warning('No requisites found for company', [
                    'company_id' => $companyId,
                ]);
                return null;
            }

            $requisite = $response['result'][0];

            // Вариант 1: GUID есть в реквизите B24
            if (!empty($requisite['UF_CRM_GUID_1C'])) {
                return $requisite['UF_CRM_GUID_1C'];
            }

            // Вариант 2: Ищем локально по b24_id реквизита
            $requisiteId = $requisite['ID'];
            $counterparty = Counterparty::where('b24_id', $requisiteId)->first();

            if ($counterparty && $counterparty->guid_1c) {
                return $counterparty->guid_1c;
            }

            Log::debug('Counterparty GUID not found', [
                'company_id' => $companyId,
                'requisite_id' => $requisiteId,
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Failed to find counterparty GUID', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Парсинг даты
     */
    protected function parseDate(?string $dateStr): ?\Carbon\Carbon
    {
        if (!$dateStr) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($dateStr);
        } catch (\Exception $e) {
            Log::warning('Failed to parse date', ['date' => $dateStr]);
            return null;
        }
    }

    /**
     * Парсинг boolean (B24 возвращает 'Y'/'N' или 1/0)
     */
    protected function parseBoolean($value): bool
    {
        if ($value === 'Y' || $value === '1' || $value === 1 || $value === true) {
            return true;
        }

        return false;
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
