<?php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Bitrix24\app\Services\Bitrix24Service;
use Modules\Bitrix24\app\Services\Pull\ContractPuller;

class FillContractLastUpdateField extends Command
{
    protected $signature = 'b24:fill-contract-last-update-field
                            {--skip-date : Skip filling last_update_from_1c field}
                            {--skip-requisite : Skip filling requisite_id field}';

    protected $description = 'Fill last_update_from_1c and requisite_id fields for all Contracts (SPA 1064)';

    protected Bitrix24Service $b24Service;

    const ENTITY_TYPE_ID = 1064; // Договоры

    public function __construct(Bitrix24Service $b24Service)
    {
        parent::__construct();
        $this->b24Service = $b24Service;
    }

    public function handle()
    {
        $this->info("=== Filling Contract fields ===\n");

        try {
            // Получаем имя поля из пуллера
            $puller = new ContractPuller($this->b24Service);
            $lastUpdateFieldName = $puller->getLastUpdateFrom1CFieldName();
            $requisiteFieldName = 'ufCrm19RequisiteId';

            $skipDate = $this->option('skip-date');
            $skipRequisite = $this->option('skip-requisite');

            $this->info("[INFO] Fields:");
            $this->info(" - Last update: {$lastUpdateFieldName}" . ($skipDate ? ' (SKIPPED)' : ''));
            $this->info(" - Requisite ID: {$requisiteFieldName}" . ($skipRequisite ? ' (SKIPPED)' : ''));

            // Значение даты по умолчанию
            $dateValue = '2026-01-08T07:20:00+03:00';
            $this->info(" - Date value: {$dateValue}\n");

            // Получаем общее количество
            $this->info("[1/2] Counting contracts...");

            $countResponse = $this->b24Service->call('crm.item.list', [
                'entityTypeId' => self::ENTITY_TYPE_ID,
                'select' => ['id'],
                'start' => 0,
            ]);

            $totalContracts = $countResponse['total'] ?? 0;
            $this->info(" - Total: {$totalContracts}");

            if ($totalContracts === 0) {
                $this->warn("\nNo contracts found!");
                return self::SUCCESS;
            }

            // Заполнение
            $this->info("\n[2/2] Updating contracts...");

            $progressBar = $this->output->createProgressBar($totalContracts);
            $progressBar->start();

            $start = 0;
            $processed = 0;
            $errors = 0;
            $requisiteNotFound = 0;

            do {
                $listResponse = $this->b24Service->call('crm.item.list', [
                    'entityTypeId' => self::ENTITY_TYPE_ID,
                    'order' => ['id' => 'ASC'],
                    'select' => ['id', 'companyId'],
                    'start' => $start,
                ]);

                $items = $listResponse['result']['items'] ?? [];

                if (empty($items)) {
                    break;
                }

                foreach ($items as $item) {
                    try {
                        $fields = [];

                        // 1. Заполняем дату обновления
                        if (!$skipDate) {
                            $fields[$lastUpdateFieldName] = $dateValue;
                        }

                        // 2. Заполняем ID реквизита
                        if (!$skipRequisite && !empty($item['companyId'])) {
                            $requisiteId = $this->findRequisiteIdByCompanyId($item['companyId']);

                            if ($requisiteId) {
                                $fields[$requisiteFieldName] = $requisiteId;
                            } else {
                                $requisiteNotFound++;
                            }
                        }

                        // Если есть что обновлять - обновляем
                        if (!empty($fields)) {
                            $this->b24Service->call('crm.item.update', [
                                'entityTypeId' => self::ENTITY_TYPE_ID,
                                'id' => $item['id'],
                                'fields' => $fields,
                            ]);
                        }

                        $processed++;
                        $progressBar->advance();

                        usleep(300000); // 0.3 сек

                    } catch (\Exception $e) {
                        $errors++;
                        $this->newLine();
                        $this->error(" - Error updating {$item['id']}: " . $e->getMessage());
                    }
                }

                $start = $listResponse['next'] ?? null;

            } while ($start);

            $progressBar->finish();
            $this->newLine(2);

            // Проверка
            $this->info("[VERIFY] Checking first contract...");

            $checkResponse = $this->b24Service->call('crm.item.list', [
                'entityTypeId' => self::ENTITY_TYPE_ID,
                'filter' => [],
                'select' => ['id', 'companyId', $lastUpdateFieldName, $requisiteFieldName],
                'start' => 0,
            ]);

            if (!empty($checkResponse['result']['items'][0])) {
                $firstItem = $checkResponse['result']['items'][0];
                $this->info(" - Contract ID: {$firstItem['id']}");
                $this->info(" - Company ID: " . ($firstItem['companyId'] ?? 'N/A'));

                if (!$skipDate) {
                    $savedDate = $firstItem[$lastUpdateFieldName] ?? 'EMPTY';
                    $this->info(" - Last update: {$savedDate}");
                }

                if (!$skipRequisite) {
                    $savedRequisite = $firstItem[$requisiteFieldName] ?? 'EMPTY';
                    $this->info(" - Requisite ID: {$savedRequisite}");
                }
            }

            // Итог
            $this->newLine();
            $this->info("=== COMPLETED ===");

            $tableData = [
                ['Total', $totalContracts],
                ['Processed', $processed],
                ['Errors', $errors],
            ];

            if (!$skipRequisite) {
                $tableData[] = ['Requisite not found', $requisiteNotFound];
            }

            $this->table(['Metric', 'Count'], $tableData);

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error("Fatal error: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Найти ID первого реквизита компании
     */
    protected function findRequisiteIdByCompanyId(int $companyId): ?int
    {
        try {
            $response = $this->b24Service->call('crm.requisite.list', [
                'filter' => [
                    'ENTITY_TYPE_ID' => 4, // Компания
                    'ENTITY_ID' => $companyId,
                ],
                'select' => ['ID'],
                'order' => ['ID' => 'ASC'],
            ]);

            if (!empty($response['result'][0]['ID'])) {
                return (int) $response['result'][0]['ID'];
            }

            return null;

        } catch (\Exception $e) {
            $this->newLine();
            $this->warn(" - Failed to get requisite for company {$companyId}: " . $e->getMessage());
            return null;
        }
    }
}
