<?php

namespace Modules\Bitrix24\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\Bitrix24\app\Services\SyncChangeProcessor;

class ProcessChanges extends Command
{
    protected $signature = 'b24:process-changes';
    protected $description = 'Process pending changes for Bitrix24';

    public function handle(SyncChangeProcessor $processor)
    {
        $this->info('Starting changes processing...');

        try {
            $processor->process();
            $this->info('Processing completed');
        } catch (\Exception $e) {
            $this->error('Processing error: ' . $e->getMessage());
        }
    }
}
