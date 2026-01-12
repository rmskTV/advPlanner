<?php

namespace Modules\Bitrix24\app\Services\Pull;

use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\Contract;
use Modules\Bitrix24\app\Models\B24SyncState;
use Modules\Bitrix24\app\Services\Mappers\B24ContractMapper;

class ContractPuller extends AbstractPuller
{
    const SPA_ID = 1064;

    protected function getEntityType(): string
    {
        return B24SyncState::ENTITY_CONTRACT;
    }

    protected function getB24Method(): string
    {
        return 'crm.item';
    }

    protected function getSelectFields(): array
    {
        return [
            'id',
            'title',
            'createdTime',
            'updatedTime',
            'companyId',
            'contactId',
            'assignedById',
            'ufCrm19ContractNo',
            'ufCrm19ContractDate',
            'ufCrm19RequisiteId',
            'ufCrm_19_BASIS',
            'ufCrm_19_GUID_1C',
            'ufCrm_19_IS_EDO',
            'ufCrm_19_IS_ANNULLED',
            'ufCrm_19_LAST_UPDATE_FROM_1C',
        ];
    }

    protected function getGuid1CFieldName(): string
    {
        return 'ufCrm_19_GUID_1C';
    }

    public function getLastUpdateFrom1CFieldName(): string
    {
        return 'ufCrm_19_LAST_UPDATE_FROM_1C';
    }

    protected function fetchChangedItems(?\Carbon\Carbon $lastSync): array
    {
        $filter = [];

        if ($lastSync) {
            /*
             * WORKAROUND: Баг Битрикс24 REST API (crm.item.list)
             *
             * При фильтрации по полю updatedTime Битрикс24 игнорирует указание
             * часового пояса (суффиксы Z, +03:00 и т.д.) и сравнивает только
             * datetime-часть как "наивное" время.
             *
             * Пример проблемы:
             *   Фильтр: filter[>updatedTime]=2026-01-07T13:29:13Z (UTC)
             *   Запись: updatedTime: "2026-01-07T11:29:15+03:00" (= 08:29:15 UTC)
             *   Ожидание: запись НЕ попадёт (08:29 < 13:29 в UTC)
             *   Реальность: запись попадает, т.к. Б24 сравнивает 11:29 vs 13:29
             *
             * Решение: вручную пересчитываем время — добавляем 8 часов смещения
             * и суффикс 'C' для корректной фильтрации на стороне Б24.
             *
             * @see https://idea.1c-bitrix.ru/ — если баг будет исправлен, этот
             *      workaround нужно будет убрать
             */
            $adjustedTime = (clone $lastSync)->modify('+8 hours');
            $filter['>updatedTime'] = $adjustedTime->format('Y-m-d\TH:i:s') . 'C';
        }

        $response = $this->b24Service->call('crm.item.list', [
            'entityTypeId' => self::SPA_ID,
            'filter' => $filter,
            'select' => $this->getSelectFields(),
            'order' => ['updatedTime' => 'ASC'],
        ]);

        return $response['result']['items'] ?? [];
    }

    protected function updateGuidInB24(int $b24Id, string $guid): void
    {
        try {
            $this->b24Service->call('crm.item.update', [
                'entityTypeId' => self::SPA_ID,
                'id' => $b24Id,
                'fields' => [
                    $this->getGuid1CFieldName() => $guid,
                ],
            ]);

            Log::debug('GUID updated in B24 contract', [
                'b24_id' => $b24Id,
                'guid' => $guid,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update GUID in B24 contract', [
                'b24_id' => $b24Id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function mapToLocal(array $b24Item): array
    {
        $mapper = new B24ContractMapper($this->b24Service);
        return $mapper->map($b24Item);
    }

    protected function findOrCreateLocal(int $b24Id)
    {
        return Contract::firstOrNew(['b24_id' => $b24Id]);
    }

    /**
     * Получить класс модели для поиска
     * Должен быть переопределён в наследниках
     */
    protected function getModelClass(): string
    {
        return  Contract::class;
    }
}
