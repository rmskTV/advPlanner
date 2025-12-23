<?php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class CreateSPAContracts extends Command
{
    /**
     * Имя и сигнатура консольной команды.
     *
     * @var string
     */
    protected $signature = 'b24:create-spa-contracts';

    /**
     * Описание консольной команды.
     *
     * @var string
     */
    protected $description = 'Create Smart Process "Contracts" and its custom fields in B24 via API';

    protected $b24Service;

    /**
     * Создание нового экземпляра команды.
     */
    public function __construct(Bitrix24Service $b24Service)
    {
        parent::__construct();
        $this->b24Service = $b24Service;
    }

    /**
     * Выполнение консольной команды.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Starting Smart Process creation sequence...');

        try {
            // ==========================================
            // ШАГ 1: Создание самого Смарт-процесса
            // ==========================================
            $this->info("\n--- Step 1: Creating SPA Container (crm.type.add) ---");

            $spaParams = [
                'fields' => [
                    'title' => 'Договоры', // Название в меню
                    'code' => 'contract_registry', // Символьный код для удобства
                    // ВАЖНО: Привязки к системным сущностям CRM
                    'linkedUserFields' => [
                        'COMPANY_ID', // Связь с Компанией
                        'CONTACT_ID',  // Связь с Контактом
                    ],
                    // Включение необходимых фич
                    'isStagesEnabled' => 'Y',     // Канбан и стадии
                    'isAutomationEnabled' => 'Y', // Роботы
                    'isBizProcEnabled' => 'Y',    // Бизнес-процессы
                    'isActivitiesEnabled' => 'Y', // Дела и задачи
                    'isSetOpenPermissions' => 'Y', // Упрощенные права доступа на старте
                ],
            ];

            $spaResult = $this->b24Service->call('crm.type.add', $spaParams);

            if (! isset($spaResult['result']['type']['id'])) {
                throw new \Exception('Failed to create SPA. Response: '.json_encode($spaResult));
            }

            // Получаем критически важный ID нового типа сущности (например, 156)
            $entityTypeId = $spaResult['result']['type']['id'];
            $this->info("SUCCESS: SPA 'Договоры' created. Entity Type ID: ".$entityTypeId);

            // ==========================================
            // ШАГ 2: Создание пользовательских полей
            // ==========================================
            $this->info("\n--- Step 2: Creating Custom Fields (crm.item.userfield.add) ---");

            // Формируем обязательный префикс для полей этого смарт-процесса
            // Формат строго: UF_CRM_{EntityTypeId}_
            $fieldPrefix = "UF_CRM_{$entityTypeId}_";
            $this->info('Using field prefix: '.$fieldPrefix);

            // Определение полей для создания
            $fieldsDefinitions = [
                [
                    'suffix' => 'CONTRACT_NO',
                    'type' => 'string',
                    'label' => 'Номер договора',
                    'mandatory' => 'Y', // Сделаем обязательным
                ],
                [
                    'suffix' => 'CONTRACT_DATE',
                    'type' => 'date',
                    'label' => 'Дата договора',
                    'mandatory' => 'Y',
                ],
                [
                    'suffix' => 'SIGNER_BASIS',
                    'type' => 'string',
                    'label' => 'Основание полномочий подписанта',
                ],
                [
                    'suffix' => 'GUID_1C',
                    'type' => 'string',
                    'label' => 'GUID 1C (Договор)',
                    'show_in_list' => 'N', // Скрываем из списка по умолчанию (техническое)
                ],
                [
                    'suffix' => 'SCAN_FILE',
                    'type' => 'file',
                    'label' => 'Скан-копия договора',
                ],
                [
                    'suffix' => 'IS_EDO',
                    'type' => 'boolean',
                    'label' => 'Подписан по ЭДО',
                    // Настройка отображения в виде чекбокса
                    'settings' => ['DISPLAY' => 'CHECKBOX'],
                ],
                [
                    'suffix' => 'IS_ANNULLED',
                    'type' => 'boolean',
                    'label' => 'Признак аннулирования',
                    'settings' => ['DISPLAY' => 'CHECKBOX'],
                ],
            ];

            foreach ($fieldsDefinitions as $fieldDef) {
                // Собираем полное имя поля, например: UF_CRM_156_CONTRACT_NO
                $fullFieldName = $fieldPrefix.$fieldDef['suffix'];

                $this->info("Creating field: {$fullFieldName} ({$fieldDef['label']})...");

                // =====================================================================
                // ВАЖНО: Подготовка параметров для метода userfieldconfig.add
                // =====================================================================
                $fieldParams = [
                    'moduleId' => 'crm', // Обязательно указываем модуль CRM
                    'field' => [ // Все настройки поля оборачиваем в массив 'field'
                        'entityId' => 'CRM_'.$entityTypeId,
                        'fieldName' => $fullFieldName,
                        'userTypeId' => $fieldDef['type'],
                        'editFormLabel' => ['ru' => $fieldDef['label']], // Обратите внимание: editFormLabel вместо EDIT_FORM_LABEL
                        'listColumnLabel' => ['ru' => $fieldDef['label']],
                        'listFilterLabel' => ['ru' => $fieldDef['label']],
                        'mandatory' => $fieldDef['mandatory'] ?? 'N',
                        'showInList' => $fieldDef['show_in_list'] ?? 'Y',
                    ],
                ];

                // Добавляем специфические настройки
                if (isset($fieldDef['settings'])) {
                    $fieldParams['field']['settings'] = $fieldDef['settings'];
                }

                // Вызов правильного метода API
                $fieldResult = $this->b24Service->call('userfieldconfig.add', $fieldParams);

                if (isset($fieldResult['result'])) {
                    // В новом методе ID поля возвращается внутри массива
                    $newFieldId = $fieldResult['result']['id'] ?? 'unknown';
                    $this->info('Done. Field ID: '.$newFieldId);
                } else {
                    $this->error("Failed to create field {$fullFieldName}. Error: ".json_encode($fieldResult));
                }
                // Небольшая пауза между запросами
                usleep(200000);
            }

            $this->info("\nSuccessfully completed SPA setup sequence.");
            $this->info("Go to B24 CRM -> Smart Processes to see 'Договоры'.");

        } catch (\Exception $e) {
            $this->error('Error during SPA creation sequence: '.$e->getMessage());
        }
    }
}

//
// "result": {
//    "types": [
//      {
//         "id": 19,
//        "title": "Договоры",
//        "code": "contract_registry",
//        "createdBy": 1,
//        "entityTypeId": 1064,
//        "customSectionId": null,
//        "isCategoriesEnabled": "N",
//        "isStagesEnabled": "Y",
//        "isBeginCloseDatesEnabled": "N",
//        "isClientEnabled": "N",
//        "isUseInUserfieldEnabled": "N",
//        "isLinkWithProductsEnabled": "N",
//        "isMycompanyEnabled": "N",
//        "isDocumentsEnabled": "N",
//        "isSourceEnabled": "N",
//        "isObserversEnabled": "N",
//        "isRecurringEnabled": "N",
//        "isRecyclebinEnabled": "N",
//        "isAutomationEnabled": "Y",
//        "isBizProcEnabled": "Y",
//        "isSetOpenPermissions": "Y",
//        "isPaymentsEnabled": "N",
//        "isCountersEnabled": "N",
//        "createdTime": "2025-12-03T06:52:10+03:00",
//        "updatedTime": "2025-12-03T06:52:10+03:00",
//        "updatedBy": 1,
//        "isInitialized": "Y"
//      }
//    ]
//  },
//
//
// Starting Smart Process creation sequence...
//
// --- Step 1: Creating SPA Container (crm.type.add) ---
// SUCCESS: SPA 'Договоры' created. Entity Type ID: 19
//
// --- Step 2: Creating Custom Fields (crm.item.userfield.add) ---
// Using field prefix: UF_CRM_19_
// Creating field: UF_CRM_19_CONTRACT_NO (Номер договора)...
// Done. Field ID: unknown
// Creating field: UF_CRM_19_CONTRACT_DATE (Дата договора)...
// Done. Field ID: unknown
// Creating field: UF_CRM_19_SIGNER_BASIS (Основание полномочий подписанта)...
// Done. Field ID: unknown
// Creating field: UF_CRM_19_GUID_1C (GUID 1C (Договор))...
// Done. Field ID: unknown
// Creating field: UF_CRM_19_SCAN_FILE (Скан-копия договора)...
// Done. Field ID: unknown
// Creating field: UF_CRM_19_IS_EDO (Подписан по ЭДО)...
// Done. Field ID: unknown
// Creating field: UF_CRM_19_IS_ANNULLED (Признак аннулирования)...
// Done. Field ID: unknown
//
// Successfully completed SPA setup sequence.
// Go to B24 CRM -> Smart Processes to see 'Договоры'.
