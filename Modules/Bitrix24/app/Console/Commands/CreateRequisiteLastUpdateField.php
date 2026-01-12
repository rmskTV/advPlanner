<?php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class CreateRequisiteLastUpdateField extends Command
{
    protected $signature = ' b24:create-datetime-requisite-last-update-field';
    protected $description = 'Create datetime field and fill records one by one';

    protected $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        parent::__construct();
        $this->b24Service = $b24Service;
    }

    public function handle()
    {
        $this->info("=== Creating DATETIME field (single updates) ===\n");

        try {
            // ----------------------------------------
            // 1. УДАЛЕНИЕ СТАРЫХ ПОЛЕЙ
            // ----------------------------------------
            $this->info("[1/4] Deleting old fields...");

            $fields = $this->b24Service->call('crm.requisite.userfield.list', []);

            foreach ($fields['result'] as $field) {
                if (str_contains($field['FIELD_NAME'], 'LAST_UPDATE')) {
                    $this->info(" - Deleting: {$field['FIELD_NAME']}");
                    $this->b24Service->call('crm.requisite.userfield.delete', ['id' => $field['ID']]);
                }
            }

            sleep(2);

            // ----------------------------------------
            // 2. СОЗДАНИЕ DATETIME ПОЛЯ
            // ----------------------------------------
            $this->info("\n[2/4] Creating DATETIME field...");

            $result = $this->b24Service->call('crm.requisite.userfield.add', [
                'fields' => [
                    'FIELD_NAME' => 'UF_LAST_UPDATE_1C',
                    'USER_TYPE_ID' => 'datetime',
                    'XML_ID' => 'UF_LAST_UPDATE_1C_DT',
                    'EDIT_FORM_LABEL' => ['ru' => 'Дата обновления из 1С'],
                    'LIST_COLUMN_LABEL' => ['ru' => 'Обновлено 1С'],
                    'SHOW_IN_LIST' => 'Y',
                    'EDIT_IN_LIST' => 'Y',
                    'IS_SEARCHABLE' => 'N',
                    'MANDATORY' => 'N',
                    'SETTINGS' => [
                        'DEFAULT_VALUE' => [
                            'TYPE' => 'FIXED',
                            'VALUE' => '31.12.2025 00:00:00'
                        ],
                        'USE_SECOND' => 'Y',
                        'USE_TIMEZONE' => 'N',
                    ]
                ],
            ]);

            if (empty($result['result'])) {
                throw new \Exception('Failed to create field');
            }

            $fieldId = $result['result'];
            $fieldInfo = $this->b24Service->call('crm.requisite.userfield.get', ['id' => $fieldId]);
            $fieldName = $fieldInfo['result']['FIELD_NAME'];

            $this->info(" - Created: {$fieldName} (ID: {$fieldId})");

            // ----------------------------------------
            // 3. ДОБАВЛЕНИЕ В ПРЕСЕТЫ
            // ----------------------------------------
            $this->info("\n[3/4] Attaching to presets...");

            $presets = $this->b24Service->call('crm.requisite.preset.list', [
                'filter' => ['ACTIVE' => 'Y']
            ]);

            foreach ($presets['result'] as $preset) {
                try {
                    $this->b24Service->call('crm.requisite.preset.field.add', [
                        'presetId' => $preset['ID'],
                        'fieldName' => $fieldName,
                        'label' => 'Дата обновления 1С',
                        'inShortList' => 'Y'
                    ]);
                    $this->info(" - Added to: {$preset['NAME']}");
                } catch (\Exception $e) {
                    // Игнорируем
                }
            }

            $this->info(" - Waiting 3 seconds...");
            sleep(3);

            // ----------------------------------------
            // 4. ЗАПОЛНЕНИЕ ПО ОДНОЙ ЗАПИСИ
            // ----------------------------------------
            $this->info("\n[4/4] Filling records ONE BY ONE...");

            // Используем ISO формат (как показал скриншот - он работает)
            $dateValue = '2025-12-31T00:00:00+03:00';

            $this->info(" - Using value: {$dateValue}");

            $start = 0;
            $total = 0;

            do {
                $list = $this->b24Service->call('crm.requisite.list', [
                    'order' => ['ID' => 'ASC'],
                    'select' => ['ID'],
                    'start' => $start
                ]);

                if (empty($list['result'])) break;

                foreach ($list['result'] as $req) {
                    $this->b24Service->call('crm.requisite.update', [
                        'id' => $req['ID'],
                        'fields' => [$fieldName => $dateValue]
                    ]);

                    $total++;

                    if ($total % 50 === 0) {
                        $this->info(" - Updated: {$total} records...");
                    }

                    usleep(300000); // 0.3 сек
                }

                $start = $list['next'] ?? null;

            } while ($start);

            // Проверка через LIST (не GET!)
            $this->info("\n[VERIFY] Checking via crm.requisite.list...");
            $check = $this->b24Service->call('crm.requisite.list', [
                'filter' => ['ID' => 1],
                'select' => ['ID', $fieldName]
            ]);

            $saved = $check['result'][0][$fieldName] ?? 'EMPTY';
            $this->info(" - Value: {$saved}");

            $this->info("\n=== DONE! Updated {$total} records ===");

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}
