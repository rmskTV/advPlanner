<?php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class CreateCRMReqCustomFields extends Command
{
    protected $signature = 'b24:create-crm-req-fields';

    protected $description = 'Create custom fields for CRM entities in B24';

    protected $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        parent::__construct();
        $this->b24Service = $b24Service;
    }

    public function handle()
    {
        try {

            $this->info("\nCreating GUID_1C field for requisites:");
            $result = $this->b24Service->call('crm.requisite.userfield.add', [
                'fields' => [
                    // Имя поля должно начинаться с UF_
                    'FIELD_NAME' => 'UF_GUID_1C',
                    'EDIT_FORM_LABEL' => ['ru' => 'GUID 1C (Реквизит)'],
                    'LIST_COLUMN_LABEL' => ['ru' => 'GUID 1C'],
                    'USER_TYPE_ID' => 'string',
                    // Рекомендуется уникальный XML_ID для разных сущностей
                    'XML_ID' => 'UF_GUID_1C_REQ',
                    'MANDATORY' => 'N',
                    // Настройки для скрытия технического поля из интерфейса:
                    'SHOW_IN_LIST' => 'N',  // Не показывать в списке
                    // 'EDIT_IN_LIST' => 'N', // Можно запретить редактирование в списке
                    // 'IS_SEARCHABLE' => 'Y' // Если нужно искать реквизит по этому полю через API фильтры, лучше явно указать Y
                ],
            ]);
            $this->info('Result: '.print_r($result, true));
            // ------------------

            $this->info("\nAll CRM custom fields created successfully");

        } catch (\Exception $e) {
            $this->error('Error creating custom fields: '.$e->getMessage());
        }
    }
}

//
//
// Creating GUID_1C field for requisites:
//    Result: Array
// (
//    [result] => 283
//    [time] => Array
// (
//    [start] => 1764304174
//            [finish] => 1764304174.4461
//            [duration] => 0.44609498977661
//            [processing] => 0
//            [date_start] => 2025-11-28T07:29:34+03:00
//            [date_finish] => 2025-11-28T07:29:34+03:00
//            [operating_reset_at] => 1764304774
//            [operating] => 0.16982913017273
//        )
//
// )
