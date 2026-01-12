<?php

namespace Modules\Bitrix24\app\Services\Mappers;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class B24RequisiteMapper
{
    protected Bitrix24Service $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * Маппинг реквизита B24 → Counterparty
     */
    public function map(array $b24Requisite): array
    {
        $presetId = (int) ($b24Requisite['PRESET_ID'] ?? 1);
        $isIp = ($presetId === 3); // 3 = ИП, 1 = Организация

        $data = [
            // Основные поля
            'name' => $this->extractName($b24Requisite, $isIp),
            'full_name' => $this->cleanString($b24Requisite['RQ_COMPANY_FULL_NAME'] ?? $b24Requisite['RQ_COMPANY_NAME'] ?? null),

            // Тип контрагента
            'entity_type' => $isIp ? Counterparty::ENTITY_TYPE_INDIVIDUAL : Counterparty::ENTITY_TYPE_LEGAL,

            // Реквизиты
            'inn' => $this->cleanString($b24Requisite['RQ_INN'] ?? null),
            'kpp' => $this->cleanString($b24Requisite['RQ_KPP'] ?? null),
            'ogrn' => $this->cleanString(
                (!empty($b24Requisite['RQ_OGRN']) ? $b24Requisite['RQ_OGRN'] :
                (!empty($b24Requisite['RQ_OGRNIP']) ? $b24Requisite['RQ_OGRNIP'] : null))
            ),
            'okpo' => $this->cleanString($b24Requisite['RQ_OKPO'] ?? null),
        ];

        // Получаем дополнительные данные из компании-контейнера
        $companyData = $this->fetchCompanyData((int) $b24Requisite['ENTITY_ID']);
        if ($companyData) {
            $data = array_merge($data, $companyData);
        }

        return $data;
    }

    /**
     * Извлечь название контрагента
     */
    protected function extractName(array $b24Requisite, bool $isIp): ?string
    {
        if ($isIp) {
            // Для ИП собираем ФИО
            $lastName = $this->cleanString($b24Requisite['RQ_LAST_NAME'] ?? null);
            $firstName = $this->cleanString($b24Requisite['RQ_FIRST_NAME'] ?? null);
            $secondName = $this->cleanString($b24Requisite['RQ_SECOND_NAME'] ?? null);

            if ($lastName || $firstName) {
                return trim("ИП {$lastName} {$firstName} {$secondName}");
            }
        }

        // Для организации или если ФИО не заполнено
        return $this->cleanString(
            $b24Requisite['RQ_COMPANY_NAME']
            ?? $b24Requisite['NAME']
            ?? null
        );
    }

    /**
     * Получить данные компании-контейнера
     */
    protected function fetchCompanyData(int $companyId): ?array
    {
        try {
            $result = $this->b24Service->call('crm.company.get', [
                'id' => $companyId,
            ]);

            if (empty($result['result'])) {
                Log::warning('Company not found in B24', ['company_id' => $companyId]);
                return null;
            }

            $company = $result['result'];

            return [
                'phone' => $this->extractFirstPhone($company),
                'email' => $this->extractFirstEmail($company),
                'comment' => $this->cleanString($company['COMMENTS'] ?? null),
                'responsible_guid_1c' => $this->mapResponsible($company['ASSIGNED_BY_ID'] ?? null),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to fetch company data', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Извлечь первый телефон
     */
    protected function extractFirstPhone(array $b24Company): ?string
    {
        if (empty($b24Company['PHONE'])) {
            return null;
        }

        if (is_array($b24Company['PHONE']) && isset($b24Company['PHONE'][0]['VALUE'])) {
            return $this->cleanString($b24Company['PHONE'][0]['VALUE']);
        }

        return null;
    }

    /**
     * Извлечь первый email
     */
    protected function extractFirstEmail(array $b24Company): ?string
    {
        if (empty($b24Company['EMAIL'])) {
            return null;
        }

        if (is_array($b24Company['EMAIL']) && isset($b24Company['EMAIL'][0]['VALUE'])) {
            return $this->cleanString($b24Company['EMAIL'][0]['VALUE']);
        }

        return null;
    }

    /**
     * Маппинг ответственного (B24 user ID → GUID 1С)
     * TODO: реализовать через таблицу маппинга пользователей
     */
    protected function mapResponsible(?int $assignedById): ?string
    {
        if (!$assignedById) {
            return null;
        }

        // Пока возвращаем null - маппинг пользователей отдельная задача
        return null;
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
