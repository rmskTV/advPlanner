<?php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class CreateB24CustomFields extends Command
{
    protected $signature = 'b24:create-custom-fields';

    protected $description = 'Create custom fields in Bitrix24';

    protected $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        parent::__construct();
        $this->b24Service = $b24Service;
    }

    public function handle()
    {
        try {
            // Создаем поле для пользователей
            $result = $this->b24Service->call('user.userfield.add', [
                'fields' => [
                    'FIELD_NAME' => 'UF_1C_GUID',
                    'EDIT_FORM_LABEL' => ['ru' => 'GUID 1C'],
                    'LIST_COLUMN_LABEL' => ['ru' => 'GUID 1C'],
                    'USER_TYPE_ID' => 'string',
                    'XML_ID' => 'UF_1C_GUID',
                    'MANDATORY' => 'N',
                ],
            ]);

            $this->info('Custom field UF_1C_GUID created successfully');
            $this->info('Result: '.print_r($result, true));

        } catch (\Exception $e) {
            $this->error('Error creating custom field: '.$e->getMessage());
        }
    }
}

// Result: Array
// (
//    [result] => 267
//    [time] => Array
// (
//    [start] => 1763702449
//            [finish] => 1763702449.6243
//            [duration] => 0.62432408332825
//            [processing] => 0
//            [date_start] => 2025-11-21T08:20:49+03:00
//            [date_finish] => 2025-11-21T08:20:49+03:00
//            [operating_reset_at] => 1763703049
//            [operating] => 0
//        )
//
// )
