<?php

namespace Modules\Bitrix24\app\Services\Pull;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\BankAccount;
use Modules\Accounting\app\Models\Counterparty;
use Modules\Bitrix24\app\Models\B24SyncState;
use Modules\Bitrix24\app\Services\Mappers\B24RequisiteMapper;

class RequisitePuller extends AbstractPuller
{
    protected function getEntityType(): string
    {
        return B24SyncState::ENTITY_COMPANY;
    }

    protected function getB24Method(): string
    {
        return 'crm.requisite';
    }

    protected function getModelClass(): string
    {
        return Counterparty::class;
    }

    protected function getSelectFields(): array
    {
        return [
            'ID',
            'ENTITY_TYPE_ID',
            'ENTITY_ID',
            'PRESET_ID',
            'NAME',
            'DATE_CREATE',
            'DATE_MODIFY',
            'RQ_INN',
            'RQ_KPP',
            'RQ_OGRN',
            'RQ_OGRNIP',      // ← Уже было
            'RQ_OKPO',
            'RQ_COMPANY_NAME',
            'RQ_COMPANY_FULL_NAME',
            'RQ_LAST_NAME',   // Для ИП
            'RQ_FIRST_NAME',  // Для ИП
            'RQ_SECOND_NAME', // Для ИП
            'UF_CRM_GUID_1C',
            'UF_CRM_LAST_UPDATE_1C',
        ];
    }

    protected function getGuid1CFieldName(): string
    {
        return 'UF_CRM_GUID_1C';
    }

    protected function getLastUpdateFrom1CFieldName(): string
    {
        return 'UF_CRM_LAST_UPDATE_1C';
    }

    /**
     * Фильтр: только реквизиты компаний (ENTITY_TYPE_ID = 4)
     */
    /**
     * 🆕 Переопределяем для получения полных данных ИП
     */
    protected function fetchChangedItems(?\Carbon\Carbon $lastSync): array
    {
        $filter = [
            'ENTITY_TYPE_ID' => 4, // Только реквизиты компаний
        ];

        if ($lastSync) {
            $filter['>DATE_MODIFY'] = $lastSync->format('Y-m-d\TH:i:sP');
        }

        // 1. Получаем список изменённых реквизитов
        $response = $this->b24Service->call($this->getB24Method() . '.list', [
            'filter' => $filter,
            'select' => ['ID', 'PRESET_ID', 'DATE_MODIFY'], // Минимум
            'order' => ['DATE_MODIFY' => 'ASC'],
        ]);

        $items = $response['result'] ?? [];

        // 2. 🆕 Для ИП запрашиваем детально (чтобы получить RQ_OGRNIP)
        $detailedItems = [];

        foreach ($items as $item) {
            $requisiteId = (int) $item['ID'];
            $presetId = (int) ($item['PRESET_ID'] ?? 1);

            // Для ИП (preset=3) запрашиваем детально
            if ($presetId === 3) {
                $detailResponse = $this->b24Service->call('crm.requisite.get', [
                    'id' => $requisiteId,
                ]);

                $detailedItem = $detailResponse['result'] ?? null;

                if ($detailedItem) {
                    $detailedItems[] = $detailedItem;

                    Log::debug('Fetched IP requisite with details', [
                        'id' => $requisiteId,
                        'has_RQ_OGRNIP' => isset($detailedItem['RQ_OGRNIP']),
                        'RQ_OGRNIP' => $detailedItem['RQ_OGRNIP'] ?? 'empty',
                    ]);
                }
            } else {
                // Для ЮЛ используем данные из list
                $detailedItems[] = $item;
            }
        }

        return $detailedItems;
    }

    protected function mapToLocal(array $b24Item): array
    {
        $mapper = new B24RequisiteMapper($this->b24Service);
        return $mapper->map($b24Item);
    }

    /**
     * ПЕРЕОПРЕДЕЛЯЕМ для дополнительной логики поиска по ИНН
     */
    protected function findOrCreateLocalSmart(int $b24Id, ?string $guid1c)
    {
        // 1. Сначала стандартный поиск (b24_id, потом guid_1c)
        $model = parent::findOrCreateLocalSmart($b24Id, $guid1c);

        // Если запись найдена - возвращаем
        if ($model->exists) {
            return $model;
        }

        // 2. Дополнительный поиск по ИНН (для случаев дублирования)
        // Получаем ИНН из B24 данных
        $inn = $this->extractInnFromB24Item($b24Id);

        if ($inn) {
            $existingByInn = Counterparty::where('inn', $inn)
                ->whereNull('deletion_mark')
                ->orWhere('deletion_mark', false)
                ->first();

            if ($existingByInn) {

                // Решаем, что делать с дублем:
                // Вариант 1: Использовать существующую запись
                //return $existingByInn;

                //Вариант 2: Создать новую (раскомментируйте, если нужно)
                Log::info('Creating new record despite INN match');
                return new Counterparty();
            }
        }

        // 3. Создаём новую запись
        return new Counterparty();
    }

    /**
     * Извлечь ИНН из данных B24 (для дополнительной проверки)
     */
    protected function extractInnFromB24Item(int $b24Id): ?string
    {
        try {
            $result = $this->b24Service->call('crm.requisite.get', [
                'id' => $b24Id,
            ]);

            if (!empty($result['result']['RQ_INN'])) {
                return trim($result['result']['RQ_INN']);
            }
        } catch (\Exception $e) {
            Log::debug('Failed to extract INN from B24', [
                'b24_id' => $b24Id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * ПЕРЕОПРЕДЕЛЯЕМ processItem для синхронизации связанных сущностей
     */
    protected function processItem(array $b24Item): array
    {
        // Вызываем родительский метод
        $result = parent::processItem($b24Item);

        // Если не dry-run и операция успешна - синхронизируем адреса и банки
        if (!$this->dryRun && in_array($result['action'], ['created', 'updated'])) {
            $b24Id = $this->extractB24Id($b24Item);
            $guid1c = $this->extractGuid1C($b24Item);

            // Используем улучшенный метод поиска
            $localModel = $this->findOrCreateLocalSmart($b24Id, $guid1c);

            if ($localModel->exists) {
                $this->syncRelatedEntities($b24Id, $localModel);
            }
        }

        return $result;
    }

    /**
     * Синхронизация адресов и банковских счетов
     */
    protected function syncRelatedEntities(int $requisiteId, Counterparty $counterparty): void
    {
        try {
            // 1. Синхронизация адресов
            $this->syncAddresses($requisiteId, $counterparty);

            // 2. Синхронизация банковских счетов
            $this->syncBankAccounts($requisiteId, $counterparty);

        } catch (\Exception $e) {
            Log::error('Failed to sync related entities', [
                'requisite_id' => $requisiteId,
                'counterparty_id' => $counterparty->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Синхронизация адресов из B24
     */
    protected function syncAddresses(int $requisiteId, Counterparty $counterparty): void
    {
        try {
            $response = $this->b24Service->call('crm.address.list', [
                'filter' => [
                    'ENTITY_TYPE_ID' => 8, // Реквизит
                    'ENTITY_ID' => $requisiteId,
                ],
            ]);

            if (empty($response['result'])) {
                return;
            }

            foreach ($response['result'] as $address) {
                $typeId = (int) ($address['TYPE_ID'] ?? 0);
                $addressText = $this->cleanString($address['ADDRESS_1'] ?? $address['ADDRESS_2'] ?? null);

                if (!$addressText) {
                    continue;
                }

                // 1 = Юридический, 6 = Фактический
                if ($typeId === 1) {
                    $counterparty->legal_address = $addressText;
                } elseif ($typeId === 6) {
                    $counterparty->actual_address = $addressText;
                }
            }

            $counterparty->save();

        } catch (\Exception $e) {
            Log::error('Failed to sync addresses', [
                'requisite_id' => $requisiteId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Синхронизация банковских счетов из B24
     */
    protected function syncBankAccounts(int $requisiteId, Counterparty $counterparty): void
    {
        try {
            $response = $this->b24Service->call('crm.requisite.bankdetail.list', [
                'filter' => [
                    'ENTITY_ID' => $requisiteId,
                ],
            ]);

            if (empty($response['result'])) {
                return;
            }

            // Получаем существующие счета
            $existingAccounts = $counterparty->bankAccounts()
                ->get()
                ->keyBy('guid_1c');

            $processedGuids = [];

            foreach ($response['result'] as $b24Account) {
                $guid = $this->cleanString($b24Account['CODE'] ?? null);

                // Если CODE пустой - генерируем GUID и обновляем в B24
                if (!$guid) {
                    $guid = $this->generateGuid();
                    $this->updateBankDetailGuid((int) $b24Account['ID'], $guid);
                }

                $processedGuids[] = $guid;

                // Ищем существующий счёт или создаём новый
                $bankAccount = $existingAccounts->get($guid);

                if (!$bankAccount) {
                    // Дополнительный поиск по номеру счёта (на случай, если GUID потерялся)
                    $accountNumber = $this->cleanString($b24Account['RQ_ACC_NUM'] ?? null);

                    if ($accountNumber) {
                        $bankAccount = $counterparty->bankAccounts()
                            ->where('account_number', $accountNumber)
                            ->first();

                        if ($bankAccount) {
                            Log::info('Found bank account by number, updating GUID', [
                                'account_id' => $bankAccount->id,
                                'old_guid' => $bankAccount->guid_1c,
                                'new_guid' => $guid,
                                'account_number' => $accountNumber,
                            ]);
                        }
                    }
                }

                // Если не нашли - создаём новый
                if (!$bankAccount) {
                    $bankAccount = new BankAccount(['guid_1c' => $guid]);
                }

                // 🆕 Разрешаем bank_guid_1c по БИК
                $bankBik = $this->cleanString($b24Account['RQ_BIK'] ?? null);
                $bankGuid = null;

                if ($bankBik) {
                    $bankGuid = $this->resolveBankGuidByBik($bankBik);
                }

                // Заполняем данные
                $bankAccount->fill([
                    'counterparty_id' => $counterparty->id,
                    'counterparty_guid_1c' => $counterparty->guid_1c, // 🆕 ДОБАВЛЕНО!
                    'name' => $this->cleanString($b24Account['RQ_ACC_NUM'] ?? null) . ' ' .
                        $this->cleanString($b24Account['NAME'] ?? 'Основной счёт'),
                    'account_number' => $this->cleanString($b24Account['RQ_ACC_NUM'] ?? null),
                    'bank_name' => $this->cleanString($b24Account['RQ_BANK_NAME'] ?? null),
                    'bank_bik' => $bankBik,
                    'bank_correspondent_account' => $this->cleanString($b24Account['RQ_COR_ACC_NUM'] ?? null),
                    'bank_swift' => $this->cleanString($b24Account['RQ_SWIFT'] ?? null),
                    'bank_guid_1c' => $bankGuid, // 🆕 Разрешённый GUID банка

                    // 🆕 Захардкоженные значения
                    'currency_id' => 1,
                    'currency_guid_1c' => 'f1a17773-5488-11e0-91e9-00e04c771318',
                    'currency_code' => '643',
                    'account_type' => 'Расчетный',

                    'is_active' => true,
                    'deletion_mark' => false,
                ]);

                $bankAccount->save();

                Log::debug('Bank account synced', [
                    'guid' => $guid,
                    'account_number' => $bankAccount->account_number,
                    'counterparty_guid' => $counterparty->guid_1c,
                    'bank_bik' => $bankBik,
                    'bank_guid' => $bankGuid,
                ]);
            }

            // Помечаем удалёнными счета, которых нет в B24
            $counterparty->bankAccounts()
                ->whereNotIn('guid_1c', $processedGuids)
                ->update(['deletion_mark' => true, 'is_active' => false]);

        } catch (\Exception $e) {
            Log::error('Failed to sync bank accounts', [
                'requisite_id' => $requisiteId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 🆕 Разрешить bank_guid_1c по БИК
     *
     * Логика:
     * 1. Ищем другой счёт с таким же БИК
     * 2. Если есть - используем его bank_guid_1c
     * 3. Если нет - генерируем новый GUID и запоминаем для этого БИК
     */
    protected function resolveBankGuidByBik(string $bik): ?string
    {
        if (empty($bik)) {
            return null;
        }

        // 1. Ищем существующий счёт с таким БИК
        $existingAccount = BankAccount::where('bank_bik', $bik)
            ->whereNotNull('bank_guid_1c')
            ->where('bank_guid_1c', '!=', '')
            ->first();

        if ($existingAccount && $existingAccount->bank_guid_1c) {
            Log::debug('Reusing bank_guid_1c from existing account', [
                'bik' => $bik,
                'bank_guid' => $existingAccount->bank_guid_1c,
                'source_account_id' => $existingAccount->id,
            ]);

            return $existingAccount->bank_guid_1c;
        }

        // 2. Не нашли - генерируем новый GUID для этого БИК
        $newBankGuid = $this->generateGuid();

        Log::info('Generated new bank_guid_1c for BIK', [
            'bik' => $bik,
            'bank_guid' => $newBankGuid,
        ]);

        return $newBankGuid;
    }

    /**
     * Обновить CODE (GUID) у банковского счёта в B24
     */
    protected function updateBankDetailGuid(int $bankDetailId, string $guid): void
    {
        try {
            $this->b24Service->call('crm.requisite.bankdetail.update', [
                'id' => $bankDetailId,
                'fields' => [
                    'CODE' => $guid,
                ],
            ]);

            Log::info('Bank detail GUID updated in B24', [
                'bank_detail_id' => $bankDetailId,
                'guid' => $guid,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update bank detail GUID in B24', [
                'bank_detail_id' => $bankDetailId,
                'guid' => $guid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function extractDateModify(array $b24Item): ?\Carbon\Carbon
    {
        $dateStr = !empty($b24Item['DATE_MODIFY'])
            ? $b24Item['DATE_MODIFY']
            : ($b24Item['DATE_CREATE'] ?? null);

        return $this->parseB24DateTime($dateStr);
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

    /**
     * Ключевые поля для предпросмотра
     */
    protected function getKeyFieldsForPreview(array $localData): array
    {
        return array_filter([
            'name' => $localData['name'] ?? null,
            'entity_type' => $localData['entity_type'] ?? null,
            'inn' => $localData['inn'] ?? null,
            'kpp' => $localData['kpp'] ?? null,
        ]);
    }
}
