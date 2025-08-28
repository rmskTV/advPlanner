<?php

namespace Modules\VkAds\app\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\VkAds\app\Events\SyncFailed;
use Modules\VkAds\app\Jobs\SyncVkAdsData;

class RetryFailedSync implements ShouldQueue
{
    public function handle(SyncFailed $event): void
    {
        $maxRetries = config('vkads.sync.max_retries', 3);
        $retryDelay = config('vkads.sync.retry_delay_minutes', 15);

        // Получаем количество попыток из контекста
        $attemptNumber = $event->context['attempt'] ?? 1;

        if ($attemptNumber < $maxRetries) {
            \Log::info('Scheduling retry for failed sync', [
                'account_id' => $event->account?->id,
                'attempt' => $attemptNumber + 1,
                'max_retries' => $maxRetries,
                'delay_minutes' => $retryDelay,
            ]);

            // Запланировать повторную синхронизацию
            SyncVkAdsData::dispatch(
                $event->account?->id,
                true, // sync statistics
                null, // current date
                $attemptNumber + 1 // attempt number
            )->delay(now()->addMinutes($retryDelay));

        } else {
            \Log::error('Max retry attempts reached for sync', [
                'account_id' => $event->account?->id,
                'attempts' => $attemptNumber,
                'final_error' => $event->exception->getMessage(),
            ]);
        }
    }
}
