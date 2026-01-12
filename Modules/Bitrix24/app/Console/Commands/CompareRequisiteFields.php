<?php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class CompareRequisiteFields extends Command
{
    protected $signature = 'b24:fill-last-update-single';
    protected $description = 'Fill LAST_UPDATE field one record at a time (no batch)';

    protected $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        parent::__construct();
        $this->b24Service = $b24Service;
    }

    public function handle()
    {
        $value = '2025-12-31 00:00:00';

        $this->info("=== Filling records ONE BY ONE (no batch) ===\n");

        try {
            // 1. Находим поле
            $this->info("[1/2] Finding field...");

            $fields = $this->b24Service->call('crm.requisite.userfield.list', []);
            $fieldName = null;

            foreach ($fields['result'] as $field) {
                if (str_contains($field['FIELD_NAME'], 'LAST_UPDATE')) {
                    $fieldName = $field['FIELD_NAME'];
                    break;
                }
            }

            if (!$fieldName) {
                $this->error("Field not found!");
                return;
            }

            $this->info(" - Found: {$fieldName}");

            // 2. Тестируем на первой записи
            $this->info("\n[2/2] Updating records...");

            $start = 0;
            $total = 0;
            $success = 0;
            $failed = 0;

            do {
                $list = $this->b24Service->call('crm.requisite.list', [
                    'order' => ['ID' => 'ASC'],
                    'select' => ['ID'],
                    'start' => $start
                ]);

                if (empty($list['result'])) break;

                foreach ($list['result'] as $req) {
                    $id = $req['ID'];
                    $total++;

                    // Обновляем ОДНУ запись
                    $this->b24Service->call('crm.requisite.update', [
                        'id' => $id,
                        'fields' => [$fieldName => $value]
                    ]);

                    // Проверяем сразу
                    $check = $this->b24Service->call('crm.requisite.get', ['id' => $id]);
                    $saved = $check['result'][$fieldName] ?? null;
Log::info( $check['result']);
                    if ($saved === $value) {
                        $success++;
                    } else {
                        $failed++;
                        // Показываем только первые 5 неудач
                        if ($failed <= 5) {
                            $this->warn(" - ID {$id}: FAILED (got: " . ($saved ?: 'EMPTY') . ")");
                        }
                    }

                    // Прогресс каждые 10 записей
                    if ($total % 10 === 0) {
                        $this->info(" - Processed: {$total} (OK: {$success}, FAIL: {$failed})");
                    }

                    // Пауза 0.5 сек (лимит 2 запроса/сек, мы делаем 2: update + get)
                    usleep(500000);
                }

                $start = $list['next'] ?? null;

            } while ($start);

            $this->info("\n=== DONE ===");
            $this->info("Total: {$total}");
            $this->info("Success: {$success}");
            $this->info("Failed: {$failed}");

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}
