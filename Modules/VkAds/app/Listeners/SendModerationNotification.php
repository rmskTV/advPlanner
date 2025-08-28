<?php

namespace Modules\VkAds\app\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\VkAds\app\Events\ModerationCompleted;

class SendModerationNotification implements ShouldQueue
{
    public function handle(ModerationCompleted $event): void
    {
        try {
            // Определяем тип объекта
            $objectType = class_basename($event->model);
            $objectName = $event->model->name ?? 'Unknown';

            // Получаем email для уведомлений (можно из настроек аккаунта)
            $notificationEmail = $this->getNotificationEmail($event->model);

            if (! $notificationEmail) {
                return;
            }

            $subject = $this->getEmailSubject($objectType, $event->newStatus);
            $template = $this->getEmailTemplate($event->newStatus);

            Mail::send($template, [
                'object_type' => $objectType,
                'object_name' => $objectName,
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'moderation_comment' => $event->model->moderation_comment,
            ], function ($message) use ($notificationEmail, $subject) {
                $message->to($notificationEmail)->subject($subject);
            });

            Log::info("Moderation notification sent for {$objectType} {$event->model->id}");

        } catch (\Exception $e) {
            Log::error('Failed to send moderation notification: '.$e->getMessage());
        }
    }

    private function getNotificationEmail($model): ?string
    {
        // Логика получения email для уведомлений
        // Можно брать из настроек аккаунта или пользователя
        return config('vkads.notifications.default_email');
    }

    private function getEmailSubject(string $objectType, string $status): string
    {
        $statusText = match ($status) {
            'approved' => 'одобрен',
            'rejected' => 'отклонен',
            'reviewing' => 'на рассмотрении',
            default => $status
        };

        return "VK Ads: {$objectType} {$statusText}";
    }

    private function getEmailTemplate(string $status): string
    {
        return match ($status) {
            'approved' => 'vk-ads::emails.moderation-approved',
            'rejected' => 'vk-ads::emails.moderation-rejected',
            default => 'vk-ads::emails.moderation-status-changed'
        };
    }
}
