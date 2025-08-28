<?php

namespace Modules\VkAds\app\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\VkAds\app\Events\ModerationCompleted;

class LogModerationResult implements ShouldQueue
{
    public function handle(ModerationCompleted $event): void
    {
        $objectType = class_basename($event->model);

        \Log::info("Moderation completed: {$objectType}", [
            'object_id' => $event->model->id,
            'object_name' => $event->model->name ?? 'Unknown',
            'old_status' => $event->oldStatus,
            'new_status' => $event->newStatus,
            'moderation_comment' => $event->model->moderation_comment,
            'moderated_at' => $event->model->moderated_at,
        ]);

        // Записываем в специальную таблицу логов модерации (если есть)
        if (class_exists('\Modules\VkAds\app\Models\ModerationLog')) {
            \Modules\VkAds\app\Models\ModerationLog::create([
                'object_type' => $objectType,
                'object_id' => $event->model->id,
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'comment' => $event->model->moderation_comment,
                'moderated_at' => $event->model->moderated_at,
            ]);
        }
    }
}
