<?php

namespace Modules\Bitrix24\app\Services\Pull;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Bitrix24\app\Enums\SyncStatus;
use Modules\Bitrix24\app\Models\B24SyncState;
use Modules\Bitrix24\app\Services\Bitrix24Service;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractPuller
{
    protected Bitrix24Service $b24Service;
    protected int $batchSize = 50;
    protected bool $dryRun = false;
    protected ?Command $output = null;


    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function setOutput(Command $output): void
    {
        $this->output = $output;
    }

    /**
     * Обработать один элемент
     * @throws \Exception
     */
    protected function processItem(array $b24Item): array
    {
        $b24Id = $this->extractB24Id($b24Item);

        // 1. Проверяем фильтр по last_update_from_1c
        if (!$this->shouldImport($b24Item)) {
            if ($this->output) {
                $this->output->line("    ⊘ Skipped (not modified since 1C sync): B24 ID {$b24Id}");
            }

            Log::debug('Item skipped by last_update_from_1c filter', [
                'entity' => $this->getEntityType(),
                'b24_id' => $b24Id,
            ]);
            return ['action' => 'skipped'];
        }

        // 2. Проверяем удаление
        if ($this->isDeleted($b24Item)) {
            if ($this->dryRun) {
                return $this->previewDeletedItem($b24Item);
            }
            return $this->processDeletedItem($b24Item);
        }

        // 3. Проверяем/генерируем GUID
        $guid1c = $this->extractGuid1C($b24Item);
        $guidWasGenerated = false;

        Log::info('Existed GUID for B24 entity', [
            'entity' => $this->getEntityType(),
            'b24_id' => $b24Id,
            'guid' => $guid1c,
        ]);

        if (!$guid1c) {
            $guid1c = $this->generateGuid();
            $guidWasGenerated = true;

            if ($this->output) {
                $this->output->line("    🆕 New GUID generated: {$guid1c}");
            }

            Log::info('Generated new GUID for B24 entity', [
                'entity' => $this->getEntityType(),
                'b24_id' => $b24Id,
                'guid' => $guid1c,
            ]);
        }
        // 4. Маппинг B24 → локальная модель
        try {
            $localData = $this->mapToLocal($b24Item);
            $localData['guid_1c'] = $guid1c;
        } catch (\Exception $e) {
            if ($this->output) {
                $this->output->error("    ✗ Mapping failed: {$e->getMessage()}");
            }
            throw $e;
        }

        // === DRY RUN MODE ===
        if ($this->dryRun) {
            return $this->previewItem($b24Item, $localData, $guidWasGenerated);
        }

        // === NORMAL MODE ===

        // 5. Найти или создать локальную запись (УЛУЧШЕННАЯ ЛОГИКА)
        $localModel = $this->findOrCreateLocalSmart($b24Id, $guid1c);
        $isNew = !$localModel->exists;

        // Если запись найдена по GUID, но b24_id не совпадает - обновляем связь
        if ($localModel->exists && $localModel->b24_id != $b24Id) {
            Log::info('Linking existing local record to B24', [
                'entity' => $this->getEntityType(),
                'local_id' => $localModel->id,
                'old_b24_id' => $localModel->b24_id,
                'new_b24_id' => $b24Id,
                'guid_1c' => $guid1c,
            ]);
        }

        // 6. Обновить поля
        $localModel->fill($localData);
        $localModel->b24_id = $b24Id;
        $localModel->last_pulled_at = now();

        if (isset($localModel->deletion_mark)) {
            $localModel->deletion_mark = false;
        }

        $localModel->save();

        // 7. Если GUID был сгенерирован - отправляем обратно в B24
        if ($guidWasGenerated) {
            $this->updateGuidInB24($b24Id, $guid1c);
        }

        // 8. Залогировать изменение для отправки в 1С
        $this->logChangeFor1C($localModel, $isNew ? 'create' : 'update');

        return ['action' => $isNew ? 'created' : 'updated'];
    }


    /**
     * Умный поиск локальной записи
     * Порядок поиска:
     * 1. По b24_id (прямая связь)
     * 2. По guid_1c (запись из 1С, уже импортированная)
     * 3. Создать новую
     */
    protected function findOrCreateLocalSmart(int $b24Id, ?string $guid1c)
    {
        $modelClass = $this->getModelClass();

        // 1. Поиск по b24_id (самая надёжная связь)
        $model = $modelClass::where('b24_id', $b24Id)->first();

        if ($model) {
            Log::debug('Found local record by b24_id', [
                'entity' => $this->getEntityType(),
                'b24_id' => $b24Id,
                'local_id' => $model->id,
            ]);
            return $model;
        }

        // 2. Поиск по GUID (запись могла прийти из 1С)
        if ($guid1c) {
            $model = $modelClass::where('guid_1c', $guid1c)->first();

            if ($model) {
                return $model;
            }
        }

        // 3. Создаём новую запись
        Log::debug('Creating new local record', [
            'entity' => $this->getEntityType(),
            'b24_id' => $b24Id,
            'guid_1c' => $guid1c,
        ]);

        return new $modelClass();
    }

    /**
     * Получить класс модели для поиска
     * Должен быть переопределён в наследниках
     */
    abstract protected function getModelClass(): string;

    /**
     * @deprecated Используйте findOrCreateLocalSmart()
     */
    protected function findOrCreateLocal(int $b24Id)
    {
        return $this->findOrCreateLocalSmart($b24Id, null);
    }

    /**
     * Предпросмотр записи (dry-run)
     */
    protected function previewItem(array $b24Item, array $localData, bool $guidWasGenerated): array
    {
        $b24Id = $this->extractB24Id($b24Item);
        $localModel = $this->findOrCreateLocalSmart($b24Id, $localData['guid_1c']);
        $isNew = !$localModel->exists;

        $action = $isNew ? 'created' : 'updated';

        if ($this->output) {
            $icon = $isNew ? '➕' : '✏️';
            $actionText = $isNew ? 'CREATE' : 'UPDATE';

            $this->output->line("    {$icon} {$actionText}: B24 ID {$b24Id}");

            if ($this->output->getOutput()->isVerbose()) {
                $this->output->line("       GUID: {$localData['guid_1c']}" . ($guidWasGenerated ? ' (generated)' : ''));

                $keyFields = $this->getKeyFieldsForPreview($localData);
                foreach ($keyFields as $field => $value) {
                    $this->output->line("       {$field}: {$value}");
                }

                if (!$isNew) {
                    $changes = $this->getChanges($localModel, $localData);
                    if (!empty($changes)) {
                        $this->output->line("       Changes:");
                        foreach ($changes as $field => $change) {
                            $this->output->line("         • {$field}: {$change['old']} → {$change['new']}");
                        }
                    }
                }
            }
        }

        return ['action' => $action];
    }

    /**
     * Предпросмотр удаления (dry-run)
     */
    protected function previewDeletedItem(array $b24Item): array
    {
        $b24Id = $this->extractB24Id($b24Item);
        $localModel = $this->findOrCreateLocal($b24Id);

        if (!$localModel->exists) {
            return ['action' => 'skipped'];
        }

        if ($this->output) {
            $this->output->line("    🗑️  DELETE: B24 ID {$b24Id} (mark as deleted)");

            if ($this->output->getOutput()->isVerbose()) {
                $this->output->line("       Local ID: {$localModel->id}");
                $this->output->line("       Name: " . ($localModel->name ?? 'N/A'));
            }
        }

        return ['action' => 'deleted'];
    }

    /**
     * Получить ключевые поля для предпросмотра
     */
    protected function getKeyFieldsForPreview(array $localData): array
    {
        // Переопределяется в конкретных пуллерах
        return array_filter([
            'name' => $localData['name'] ?? null,
            'inn' => $localData['inn'] ?? null,
            'phone' => $localData['phone'] ?? null,
        ]);
    }

    /**
     * Получить изменения между старой и новой версией
     */
    protected function getChanges($model, array $newData): array
    {
        $changes = [];

        foreach ($newData as $field => $newValue) {
            if (!isset($model->$field)) {
                continue;
            }

            $oldValue = $model->$field;

            // Сравниваем только если значения разные
            if ($oldValue != $newValue) {
                $changes[$field] = [
                    'old' => $this->formatValue($oldValue),
                    'new' => $this->formatValue($newValue),
                ];
            }
        }

        return $changes;
    }

    /**
     * Форматирование значения для вывода
     */
    protected function formatValue($value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof \DateTime || $value instanceof \Carbon\Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value) && strlen($value) > 50) {
            return substr($value, 0, 47) . '...';
        }

        return (string) $value;
    }

    /**
     * Главный метод импорта
     */
    public function pull(): array
    {
        $entityType = $this->getEntityType();
        $lastSync = B24SyncState::getLastSync($entityType);

        Log::info("Starting pull for {$entityType}", [
            'last_sync' => $lastSync?->format('Y-m-d H:i:s'),
        ]);

        $stats = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'deleted' => 0,
            'errors' => 0,
        ];

        try {
            // 1. Получаем измененные записи из B24
            $items = $this->fetchChangedItems($lastSync);

            if (empty($items)) {
                Log::debug("No changes for {$entityType}");
                return $stats;
            }

            Log::info("Fetched {$entityType} items", ['count' => count($items)]);

            // 2. Обрабатываем каждую запись
            foreach ($items as $b24Item) {
                try {
                    DB::beginTransaction();

                    $result = $this->processItem($b24Item);

                    $stats['total']++;
                    $stats[$result['action']]++; // created/updated/skipped/deleted

                    DB::commit();

                } catch (\Exception $e) {
                    DB::rollBack();
                    $stats['errors']++;

                    Log::error("Error processing {$entityType}", [
                        'b24_id' => $b24Item['ID'] ?? null,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // 3. Обновляем время последней синхронизации
            $latestUpdate = $this->getLatestUpdateTime($items);
            B24SyncState::updateLastSync($entityType, $latestUpdate);

            Log::info("Pull completed for {$entityType}", $stats);

        } catch (\Exception $e) {
            Log::error("Failed to pull {$entityType}: " . $e->getMessage());
            throw $e;
        }

        return $stats;
    }

    /**
     * Получить измененные записи из B24
     */
    protected function fetchChangedItems(?\Carbon\Carbon $lastSync): array
    {
        $filter = [];

        // Фильтр по времени изменения
        if ($lastSync) {
        //    $filter['>DATE_MODIFY'] = $lastSync->format('Y-m-d\TH:i:sP');
        }

        $response = $this->b24Service->call($this->getB24Method() . '.list', [
            'filter' => $filter,
            'select' => $this->getSelectFields(),
            'order' => ['DATE_MODIFY' => 'ASC'],
        ]);

        return $response['result'] ?? [];
    }

    /**
     * Обработка удаленной записи
     */
    protected function processDeletedItem(array $b24Item): array
    {
        $b24Id = $this->extractB24Id($b24Item);
        $localModel = $this->findOrCreateLocal($b24Id);

        if (!$localModel->exists) {
            return ['action' => 'skipped'];
        }

        if (isset($localModel->deletion_mark)) {
            $localModel->deletion_mark = true;
            $localModel->last_pulled_at = now();
            $localModel->save();

            $this->logChangeFor1C($localModel, 'delete');

            Log::info('Item marked for deletion', [
                'entity' => $this->getEntityType(),
                'local_id' => $localModel->id,
                'b24_id' => $b24Id,
            ]);

            return ['action' => 'deleted'];
        }

        return ['action' => 'skipped'];
    }

    /**
     * Проверка: нужно ли импортировать (фильтр по last_update_from_1c)
     *
     * Логика: импортируем если:
     * 1. last_update_from_1c пустой (создано в B24)
     * 2. last_update_from_1c < DATE_MODIFY (менялось после импорта из 1С)
     */
    protected function shouldImport(array $b24Item): bool
    {
        $lastUpdateFrom1C = $this->extractLastUpdateFrom1C($b24Item);
        $dateModify = $this->extractDateModify($b24Item);

        if (!$dateModify) {
            Log::warning('No date modify found for item', [
                'entity' => $this->getEntityType(),
                'b24_item_keys' => array_keys($b24Item),
            ]);
            return false;
        }

        if (!$lastUpdateFrom1C) {
            return true;
        }

        return $lastUpdateFrom1C < $dateModify;
    }


    /**
     * Проверка удаления (по полям B24)
     */
    protected function isDeleted(array $b24Item): bool
    {
        // Для большинства сущностей B24 нет поля deleted
        // Переопределяется в конкретных пуллерах при необходимости
        return false;
    }

    /**
     * Генерация GUID для новых записей
     */
    protected function generateGuid(): string
    {
        return Uuid::uuid1()->toString();
    }

    /**
     * Извлечь GUID 1С из B24
     */
    protected function extractGuid1C(array $b24Item): ?string
    {
        $fieldName = $this->getGuid1CFieldName();

        return !empty($b24Item[$fieldName]) ? $b24Item[$fieldName] : null;
    }

    /**
     * Извлечь last_update_from_1c из кастомного поля
     */
    protected function extractLastUpdateFrom1C(array $b24Item): ?\Carbon\Carbon
    {
        $fieldName = $this->getLastUpdateFrom1CFieldName();

        if (empty($b24Item[$fieldName])) {
            return null;
        }

        return $this->parseB24DateTime($b24Item[$fieldName]);
    }

    /**
     * Обновить GUID в B24
     */
    protected function updateGuidInB24(int $b24Id, string $guid): void
    {
        try {
            $fields = [
                $this->getGuid1CFieldName() => $guid,
            ];

            $this->b24Service->call($this->getB24Method() . '.update', [
                'id' => $b24Id,
                'fields' => $fields,
            ]);

            Log::debug('GUID updated in B24', [
                'entity' => $this->getEntityType(),
                'b24_id' => $b24Id,
                'guid' => $guid,
            ]);

        } catch (\Exception $e) {
            // Не критично - GUID уже сохранен локально
            Log::error('Failed to update GUID in B24', [
                'entity' => $this->getEntityType(),
                'b24_id' => $b24Id,
                'guid' => $guid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Залогировать изменение в очередь для 1С
     */
    protected function logChangeFor1C($localModel, string $changeType): void
    {
        ObjectChangeLog::create([
            'entity_type' => get_class($localModel),
            'local_id' => $localModel->id,
            '1c_id' => $localModel->guid_1c ?? null, // ← Исправлено!
            'b24_id' => $localModel->b24_id ?? null, // ← Исправлено!
            //'change_type' => $changeType,
            'status' => SyncStatus::PENDING,
            'source' => ObjectChangeLog::SOURCE_B24,
        ]);
    }

    /**
     * Получить максимальное время обновления из пачки
     */
    protected function getLatestUpdateTime(array $items): \Carbon\Carbon
    {
        $latest = null;

        foreach ($items as $item) {
            $time = $this->extractDateModify($item);
            if ($time && (!$latest || $time > $latest)) {
                $latest = $time;
            }
        }

        return $latest ?? now();
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
            // Carbon автоматически распознаёт ISO 8601 с таймзоной
            // Но нужно явно конвертировать в таймзону приложения
            $date = \Carbon\Carbon::parse($dateStr);

            // Конвертируем в таймзону приложения (обычно UTC)
            $appTimezone = config('app.timezone', 'UTC');
            $date->setTimezone($appTimezone);

            return $date;

        } catch (\Exception $e) {
            Log::warning('Failed to parse B24 date', [
                'date' => $dateStr,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }


    // ========================================================================
    // АБСТРАКТНЫЕ МЕТОДЫ (переопределяются в наследниках)
    // ========================================================================

    /**
     * Тип сущности (для логирования и B24SyncState)
     */
    abstract protected function getEntityType(): string;

    /**
     * Метод B24 API (например, 'crm.company')
     */
    abstract protected function getB24Method(): string;

    /**
     * Поля для выборки из B24
     */
    abstract protected function getSelectFields(): array;

    /**
     * Имя поля GUID 1С в B24
     */
    abstract protected function getGuid1CFieldName(): string;

    /**
     * Имя кастомного поля last_update_from_1c в B24
     */
    abstract protected function getLastUpdateFrom1CFieldName(): string;

    /**
     * Маппинг данных B24 → локальная модель
     */
    abstract protected function mapToLocal(array $b24Item): array;


    protected function extractB24Id(array $b24Item): int
    {
        return (int) ($b24Item['id'] ?? $b24Item['ID'] ?? 0);
    }

    /**
     * Извлечь дату изменения (для SPA это 'updatedTime', для обычных 'DATE_MODIFY')
     */
    protected function extractDateModify(array $b24Item): ?\Carbon\Carbon
    {
        $dateStr = $b24Item['DATE_MODIFY'] ?? $b24Item['TIMESTAMP_X'] ?? $b24Item['updatedTime'] ?? null;

        return $this->parseB24DateTime($dateStr);
    }
}
