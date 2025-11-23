<?php
namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Bitrix24\app\Services\Bitrix24Service;


class CreateCatalogCustomFields extends Command
{
    protected $signature = 'b24:create-catalog-fields';
    protected $description = 'Create custom fields for catalog in B24';

    protected $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        parent::__construct();
        $this->b24Service = $b24Service;
    }

    public function handle()
    {
        try {
            // Для товаров через crm.product.property.add
            $this->info("\nCreating 1C_GUID property:");
            $result = $this->createProductProperty('GUID_1C', 'GUID 1C товара');
            $this->info('Result: ' . print_r($result, true));

            $this->info("\nCreating ANALYTICS_GROUP property:");
            $result = $this->createProductProperty('ANALYTICS_GROUP', 'Статья учета');
            $this->info('Result: ' . print_r($result, true));

            $this->info("\nCreating ANALYTICS_GROUP_GUID property:");
            $result = $this->createProductProperty('ANALYTICS_GROUP_GUID', 'GUID статьи учета');
            $this->info('Result: ' . print_r($result, true));

            $this->info("\nAll custom fields created successfully");

        } catch (\Exception $e) {
            $this->error('Error creating custom fields: ' . $e->getMessage());
            if (method_exists($e, 'getResponse')) {
                $this->error('Response: ' . print_r($e->getResponse(), true));
            }
        }
    }

    protected function createProductProperty($code, $name)
    {
        try {
            return $this->b24Service->call('crm.product.property.add', [
                'fields' => [
                    'NAME' => $name,
                    'CODE' => $code,
                    'TYPE' => 'S', // Строка
                    'ACTIVE' => 'Y',
                    'MULTIPLE' => 'N'
                ]
            ]);

        } catch (\Exception $e) {
            $this->error("Error creating property {$code}: " . $e->getMessage());
            throw $e;
        }
    }
}
//
//
//
//
//Creating ANALYTICS_GROUP property:
//Result: Array
//(
//    [result] => 123
//    [time] => Array
//(
//    [start] => 1763801242
//            [finish] => 1763801242.4901
//            [duration] => 0.49006390571594
//            [processing] => 0
//            [date_start] => 2025-11-22T11:47:22+03:00
//            [date_finish] => 2025-11-22T11:47:22+03:00
//            [operating_reset_at] => 1763801842
//            [operating] => 0
//        )
//
//)
//
//
//Creating ANALYTICS_GROUP_GUID property:
//Result: Array
//(
//    [result] => 125
//    [time] => Array
//(
//    [start] => 1763801242
//            [finish] => 1763801242.8618
//            [duration] => 0.86183595657349
//            [processing] => 0
//            [date_start] => 2025-11-22T11:47:22+03:00
//            [date_finish] => 2025-11-22T11:47:22+03:00
//            [operating_reset_at] => 1763801842
//            [operating] => 0
//        )
//
//)
//
//Creating 1C_GUID property:
//Result: Array
//(
//    [result] => 127
//    [time] => Array
//(
//    [start] => 1763801339
//            [finish] => 1763801339.9918
//            [duration] => 0.99179196357727
//            [processing] => 0
//            [date_start] => 2025-11-22T11:48:59+03:00
//            [date_finish] => 2025-11-22T11:48:59+03:00
//            [operating_reset_at] => 1763801939
//            [operating] => 0
//        )
//
//)
//
