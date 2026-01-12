<?php

namespace Modules\Bitrix24\app\Services\Mappers;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Bitrix24\app\Exceptions\DependencyNotReadyException;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class B24ContactMapper
{
    protected Bitrix24Service $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * Маппинг контакта B24 → ContactPerson
     */
    public function map(array $b24Contact): array
    {
        $data = [
            // ФИО
            'surname' => $this->cleanString($b24Contact['LAST_NAME']),
            'name' => $this->cleanString($b24Contact['NAME']),
            'patronymic' => $this->cleanString($b24Contact['SECOND_NAME']),

            // Должность
            'position' => $this->cleanString($b24Contact['POST']),

            // Контакты
            'phone' => $this->extractFirstPhone($b24Contact),
            'email' => $this->extractFirstEmail($b24Contact),

            // Комментарий
            'comment' => $this->cleanString($b24Contact['COMMENTS'] ?? null),

            // Статус (активен по умолчанию)
            'is_active' => true,
        ];

        // Связь с контрагентом через COMPANY_ID (ОБЯЗАТЕЛЬНА!)
        if (!empty($b24Contact['COMPANY_ID'])) {
            $counterpartyGuid = $this->findCounterpartyGuidByCompanyId($b24Contact['COMPANY_ID']);

            if (!$counterpartyGuid) {
                // Контрагент не синхронизирован - бросаем исключение для retry
                throw new DependencyNotReadyException(
                    "Counterparty not synced yet for company ID: {$b24Contact['COMPANY_ID']}"
                );
            }

            $data['counterparty_guid_1c'] = $counterpartyGuid;
        } else {
            // Контакт без компании - скипаем
            throw new DependencyNotReadyException(
                "Contact has no COMPANY_ID: {$b24Contact['ID']}"
            );
        }

        return $data;
    }

    /**
     * Найти GUID контрагента по ID компании B24
     *
     * Логика:
     * 1. Получаем реквизиты компании
     * 2. Берём GUID из первого реквизита (из B24 или локальной БД)
     */
    protected function findCounterpartyGuidByCompanyId(int $companyId): ?string
    {
        try {
            // Ищем реквизит этой компании
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
     * Извлечь первый телефон
     */
    protected function extractFirstPhone(array $contact): ?string
    {
        if (empty($contact['PHONE'])) {
            return null;
        }

        if (is_array($contact['PHONE']) && isset($contact['PHONE'][0]['VALUE'])) {
            return $this->cleanString($contact['PHONE'][0]['VALUE']);
        }

        return null;
    }

    /**
     * Извлечь первый email
     */
    protected function extractFirstEmail(array $contact): ?string
    {
        if (empty($contact['EMAIL'])) {
            return null;
        }

        if (is_array($contact['EMAIL']) && isset($contact['EMAIL'][0]['VALUE'])) {
            return $this->cleanString($contact['EMAIL'][0]['VALUE']);
        }

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
