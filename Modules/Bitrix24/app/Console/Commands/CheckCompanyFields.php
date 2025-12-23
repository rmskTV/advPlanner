<?php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Bitrix24\app\Services\Bitrix24Service;

class CheckCompanyFields extends Command
{
    protected $signature = 'b24:check-company-fields';

    public function handle(Bitrix24Service $b24Service)
    {
        // Получаем все пользовательские поля компании
        $fields = $b24Service->call('crm.company.userfield.list');

        $this->info('Company user fields:');
        foreach ($fields['result'] as $field) {
            $this->info(sprintf('ID: %s, Name: %s, Code: %s',
                $field['ID'],
                $field['EDIT_FORM_LABEL']['ru'] ?? 'N/A',
                $field['FIELD_NAME']
            ));
        }

        // Получаем стандартные поля
        $standardFields = $b24Service->call('crm.company.fields');

        $this->info("\n\nStandard company fields:");
        foreach ($standardFields['result'] as $fieldName => $fieldInfo) {
            if (stripos($fieldName, 'INN') !== false || stripos($fieldName, 'ИНН') !== false) {
                $this->info(sprintf('Field: %s, Title: %s',
                    $fieldName,
                    $fieldInfo['formLabel'] ?? $fieldInfo['title'] ?? 'N/A'
                ));
            }
        }
    }
}
