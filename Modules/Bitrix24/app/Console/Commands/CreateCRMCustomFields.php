<?php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class CreateCRMCustomFields extends Command
{
    protected $signature = 'b24:create-crm-fields';

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
            // Поле GUID_1C для компаний
            $this->info("\nCreating GUID_1C field for companies:");
            $result = $this->b24Service->call('crm.company.userfield.add', [
                'fields' => [
                    'FIELD_NAME' => 'UF_GUID_1C',
                    'EDIT_FORM_LABEL' => ['ru' => 'GUID 1C'],
                    'LIST_COLUMN_LABEL' => ['ru' => 'GUID 1C'],
                    'USER_TYPE_ID' => 'string',
                    'XML_ID' => 'UF_GUID_1C',
                    'MANDATORY' => 'N',
                ],
            ]);
            $this->info('Result: '.print_r($result, true));

            // Поле GUID_1C для контактов
            $this->info("\nCreating GUID_1C field for contacts:");
            $result = $this->b24Service->call('crm.contact.userfield.add', [
                'fields' => [
                    'FIELD_NAME' => 'UF_GUID_1C',
                    'EDIT_FORM_LABEL' => ['ru' => 'GUID 1C'],
                    'LIST_COLUMN_LABEL' => ['ru' => 'GUID 1C'],
                    'USER_TYPE_ID' => 'string',
                    'XML_ID' => 'UF_GUID_1C',
                    'MANDATORY' => 'N',
                ],
            ]);
            $this->info('Result: '.print_r($result, true));

            $this->info("\nAll CRM custom fields created successfully");

        } catch (\Exception $e) {
            $this->error('Error creating custom fields: '.$e->getMessage());
        }
    }
}

//
// Creating GUID_1C field for companies:
//    Result: Array
// (
//    [result] => 269
//    [time] => Array
// (
//    [start] => 1764126326
//            [finish] => 1764126326.5244
//            [duration] => 0.52441692352295
//            [processing] => 0
//            [date_start] => 2025-11-26T06:05:26+03:00
//            [date_finish] => 2025-11-26T06:05:26+03:00
//            [operating_reset_at] => 1764126926
//            [operating] => 0
//        )
//
// )
//
//
// Creating GUID_1C field for contacts:
//    Result: Array
// (
//    [result] => 271
//    [time] => Array
// (
//    [start] => 1764126326
//            [finish] => 1764126327.023
//            [duration] => 1.0229609012604
//            [processing] => 1
//            [date_start] => 2025-11-26T06:05:26+03:00
//            [date_finish] => 2025-11-26T06:05:27+03:00
//            [operating_reset_at] => 1764126926
//            [operating] => 0.14348697662354
//        )
//
// )
