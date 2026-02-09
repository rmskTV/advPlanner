<?php

namespace Modules\Bitrix24\app\Services\Pull;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Bitrix24\app\Services\Bitrix24Service;

/**
 * Сервис для ленивой загрузки зависимостей при импорте счетов
 *
 * Проверяет актуальность связанных сущностей и синхронизирует их при необходимости
 */
class DependencySyncService
{
    protected Bitrix24Service $b24Service;

    // Кэш в рамках одного запуска (чтобы не дёргать B24 для одного контрагента много раз)
    protected array $counterpartyCache = [];  // companyId => ['checked' => bool, 'guid' => string|null]
    protected array $contractCache = [];      // contractId => ['checked' => bool, 'guid' => string|null]
    protected array $requisiteCache = [];     // companyId => requisiteData

    protected bool $dryRun = false;
    protected ?\Illuminate\Console\Command $output = null;

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    public function setOutput(?\Illuminate\Console\Command $output): self
    {
        $this->output = $output;
        return $this;
    }

    /**
     * Убедиться, что контрагент синхронизирован и актуален
     *
     * @param int $companyId ID компании в B24
     * @return string|null GUID контрагента или null
     */
    public function ensureCounterparty(int $companyId): ?string
    {
        // 1. Проверяем кэш текущей сессии
        if (isset($this->counterpartyCache[$companyId])) {
            return $this->counterpartyCache[$companyId]['guid'];
        }

        try {
            // 2. Получаем реквизит компании из B24
            $requisite = $this->fetchCompanyRequisite($companyId);

            if (!$requisite) {
                Log::warning('No requisite found for company', ['company_id' => $companyId]);
                $this->counterpartyCache[$companyId] = ['checked' => true, 'guid' => null];
                return null;
            }

            $requisiteId = (int) $requisite['ID'];
            $b24UpdatedAt = $this->parseB24DateTime($requisite['DATE_MODIFY'] ?? null);
            $guid1c = $requisite['UF_CRM_GUID_1C'] ?? null;

            // 3. Ищем локальную запись
            $localCounterparty = Counterparty::where('b24_id', $requisiteId)->first();

            // Если нет по b24_id, пробуем по GUID
            if (!$localCounterparty && $guid1c) {
                $localCounterparty = Counterparty::where('guid_1c', $guid1c)->first();
            }

            // 4. Проверяем нужна ли синхронизация
            $needsSync = $this->needsSync($localCounterparty, $b24UpdatedAt);

            if ($needsSync) {
                $this->log("  📥 Syncing counterparty (company_id: {$companyId})...");

                if (!$this->dryRun) {
                    $guid1c = $this->syncRequisite($requisite);
                } else {
                    $this->log("    [DRY RUN] Would sync counterparty");
                    // В dry-run возвращаем существующий GUID если есть
                    $guid1c = $localCounterparty?->guid_1c ?? $guid1c;
                }
            } else {
                $guid1c = $localCounterparty->guid_1c;
                $this->log("  ✓ Counterparty up-to-date (company_id: {$companyId})", 'debug');
            }

            // 5. Кэшируем результат
            $this->counterpartyCache[$companyId] = ['checked' => true, 'guid' => $guid1c];

            return $guid1c;

        } catch (\Exception $e) {
            Log::error('Failed to ensure counterparty', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            $this->counterpartyCache[$companyId] = ['checked' => true, 'guid' => null];
            return null;
        }
    }

    /**
     * Убедиться, что договор синхронизирован и актуален
     *
     * @param int $contractB24Id ID договора (SPA) в B24
     * @return string|null GUID договора или null
     */
    public function ensureContract(int $contractB24Id): ?string
    {
        // 1. Проверяем кэш
        if (isset($this->contractCache[$contractB24Id])) {
            return $this->contractCache[$contractB24Id]['guid'];
        }

        try {
            // 2. Получаем договор из B24
            $b24Contract = $this->fetchContract($contractB24Id);

            if (!$b24Contract) {
                Log::warning('Contract not found in B24', ['contract_id' => $contractB24Id]);
                $this->contractCache[$contractB24Id] = ['checked' => true, 'guid' => null];
                return null;
            }

            $b24UpdatedAt = $this->parseB24DateTime($b24Contract['updatedTime'] ?? null);
            $guid1c = $b24Contract['ufCrm_19_GUID_1C'] ?? null;

            // 3. Ищем локальную запись
            $localContract = Contract::where('b24_id', $contractB24Id)->first();

            if (!$localContract && $guid1c) {
                $localContract = Contract::where('guid_1c', $guid1c)->first();
            }

            // 4. Проверяем нужна ли синхронизация
            $needsSync = $this->needsSync($localContract, $b24UpdatedAt);

            if ($needsSync) {
                $this->log("  📥 Syncing contract (b24_id: {$contractB24Id})...");

                if (!$this->dryRun) {
                    // Сначала убедимся, что контрагент договора синхронизирован
                    if (!empty($b24Contract['companyId'])) {
                        $this->ensureCounterparty($b24Contract['companyId']);
                    }

                    $guid1c = $this->syncContract($b24Contract);
                } else {
                    $this->log("    [DRY RUN] Would sync contract");
                    $guid1c = $localContract?->guid_1c ?? $guid1c;
                }
            } else {
                $guid1c = $localContract->guid_1c;
                $this->log("  ✓ Contract up-to-date (b24_id: {$contractB24Id})", 'debug');
            }

            // 5. Кэшируем
            $this->contractCache[$contractB24Id] = ['checked' => true, 'guid' => $guid1c];

            Log::info('=== ENSURE CONTRACT ===', [
                'contract_b24_id' => $contractB24Id,
                'b24_contract_found' => !empty($b24Contract),
                'b24_guid' => $b24Contract['ufCrm_19_GUID_1C'] ?? null,
                'b24_updated_at' => $b24Contract['updatedTime'] ?? null,
                'local_exists' => $localContract?->exists,
                'local_guid' => $localContract?->guid_1c,
                'needs_sync' => $needsSync,
            ]);

            return $guid1c;

        } catch (\Exception $e) {
            Log::error('Failed to ensure contract', [
                'contract_id' => $contractB24Id,
                'error' => $e->getMessage(),
            ]);

            $this->contractCache[$contractB24Id] = ['checked' => true, 'guid' => null];
            return null;
        }
    }

    /**
     * Проверить, нужна ли синхронизация
     */
    protected function needsSync($localModel, ?\Carbon\Carbon $b24UpdatedAt): bool
    {
        // Если локальной записи нет — нужна
        if (!$localModel || !$localModel->exists) {
            return true;
        }

        // Если нет даты обновления в B24 — не синхронизируем (нет данных)
        if (!$b24UpdatedAt) {
            return false;
        }

        // Если нет last_pulled_at — нужна синхронизация
        if (!$localModel->last_pulled_at) {
            return true;
        }

        // Сравниваем даты: если B24 обновлялся после последнего pull — синхронизируем
        return $b24UpdatedAt->gt($localModel->last_pulled_at);
    }

    /**
     * Получить реквизит компании из B24
     */
    protected function fetchCompanyRequisite(int $companyId): ?array
    {
        // Проверяем кэш
        if (isset($this->requisiteCache[$companyId])) {
            return $this->requisiteCache[$companyId];
        }

        $response = $this->b24Service->call('crm.requisite.list', [
            'filter' => [
                'ENTITY_TYPE_ID' => 4, // Компания
                'ENTITY_ID' => $companyId,
            ],
            'select' => [
                'ID',
                'NAME',
                'DATE_CREATE',
                'DATE_MODIFY',
                'RQ_INN',
                'RQ_KPP',
                'RQ_OGRN',
                'RQ_COMPANY_NAME',
                'RQ_COMPANY_FULL_NAME',
                'PRESET_ID',
                'UF_CRM_GUID_1C',
                'UF_CRM_LAST_UPDATE_1C',
                'ENTITY_ID',
                'ENTITY_TYPE_ID',
            ],
            'limit' => 1,
        ]);

        $requisite = $response['result'][0] ?? null;
        $this->requisiteCache[$companyId] = $requisite;

        return $requisite;
    }

    /**
     * Получить договор из B24
     */
    protected function fetchContract(int $contractId): ?array
    {
        $response = $this->b24Service->call('crm.item.get', [
            'entityTypeId' => ContractPuller::SPA_ID,
            'id' => $contractId,
        ]);

        return $response['result']['item'] ?? null;
    }

    /**
     * Синхронизировать реквизит (контрагента)
     */
    protected function syncRequisite(array $requisite): ?string
    {
        $puller = new RequisitePuller($this->b24Service);
        $puller->setDryRun($this->dryRun);

        if ($this->output) {
            $puller->setOutput($this->output);
        }

        // 🆕 ПРИНУДИТЕЛЬНАЯ синхронизация
        $result = $puller->syncSingleItem($requisite, force: true);

        return $result['guid_1c'] ?? null;
    }


    /**
     * Синхронизировать договор
     */
    protected function syncContract(array $b24Contract): ?string
    {
        $puller = new ContractPuller($this->b24Service);
        $puller->setDryRun($this->dryRun);

        if ($this->output) {
            $puller->setOutput($this->output);
        }

        // 🆕 ПРИНУДИТЕЛЬНАЯ синхронизация — игнорируем shouldImport!
        $result = $puller->syncSingleItem($b24Contract, force: true);

        return $result['guid_1c'] ?? null;
    }

    /**
     * Парсинг даты B24
     */
    protected function parseB24DateTime(?string $dateStr): ?\Carbon\Carbon
    {
        if (!$dateStr) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($dateStr)->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Логирование
     */
    protected function log(string $message, string $level = 'info'): void
    {
        if ($this->output) {
            if ($level === 'debug' && !$this->output->getOutput()->isVerbose()) {
                return;
            }
            $this->output->line($message);
        }

        Log::$level($message);
    }

    /**
     * Очистить кэш (между запусками)
     */
    public function clearCache(): void
    {
        $this->counterpartyCache = [];
        $this->contractCache = [];
        $this->requisiteCache = [];
    }

    /**
     * Получить статистику
     */
    public function getStats(): array
    {
        return [
            'counterparties_checked' => count($this->counterpartyCache),
            'contracts_checked' => count($this->contractCache),
        ];
    }
}
