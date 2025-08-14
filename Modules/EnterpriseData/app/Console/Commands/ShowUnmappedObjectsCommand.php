<?php

namespace Modules\EnterpriseData\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\EnterpriseData\app\Models\ExchangeUnmappedObject;

class ShowUnmappedObjectsCommand extends Command
{
    protected $signature = 'exchange:unmapped-objects
                           {connector? : ID коннектора}
                           {--status=pending : Статус объектов}
                           {--limit=20 : Количество записей}';

    protected $description = 'Просмотр немаппированных объектов';

    public function handle(): int
    {
        $connectorId = $this->argument('connector');
        $status = $this->option('status');
        $limit = $this->option('limit');

        $query = ExchangeUnmappedObject::with('connector');

        if ($connectorId) {
            $query->where('connector_id', $connectorId);
        }

        if ($status && $status !== 'all') {
            $query->where('mapping_status', $status);
        }

        $unmappedObjects = $query->orderByDesc('occurrence_count')
            ->limit($limit)
            ->get();

        if ($unmappedObjects->isEmpty()) {
            $this->info('Немаппированные объекты не найдены');

            return self::SUCCESS;
        }

        $this->info('=== НЕМАППИРОВАННЫЕ ОБЪЕКТЫ ===');

        $headers = ['Тип объекта', 'Коннектор', 'Встреч', 'Первый раз', 'Последний раз', 'Статус'];
        $rows = [];

        foreach ($unmappedObjects as $obj) {
            $rows[] = [
                $obj->object_type,
                $obj->connector->foreign_base_name ?? "ID: {$obj->connector_id}",
                $obj->occurrence_count,
                $obj->first_seen_at->format('d.m.Y H:i'),
                $obj->last_seen_at->format('d.m.Y H:i'),
                $obj->mapping_status,
            ];
        }

        $this->table($headers, $rows);

        // Показываем приоритетные типы
        $priorityTypes = ['Документ.ЗаказКлиента', 'Документ.РеализацияТоваровУслуг', 'Справочник.Номенклатура'];
        $foundPriority = $unmappedObjects->whereIn('object_type', $priorityTypes);

        if ($foundPriority->isNotEmpty()) {
            $this->line('');
            $this->warn('⚠ Найдены приоритетные типы объектов без маппинга:');
            foreach ($foundPriority as $obj) {
                $this->line("  - {$obj->object_type} ({$obj->occurrence_count} встреч)");
            }
        }

        return self::SUCCESS;
    }
}
