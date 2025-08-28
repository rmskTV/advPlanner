<?php

namespace Modules\VkAds\app\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\VkAds\app\Events\BudgetExhausted;

class SendBudgetAlert implements ShouldQueue
{
    public function handle(BudgetExhausted $event): void
    {
        try {
            $campaign = $event->campaign;
            $account = $campaign->account;

            // Получаем email для уведомлений
            $notificationEmail = $this->getNotificationEmail($account);

            if (! $notificationEmail) {
                Log::info("No notification email configured for account {$account->id}");

                return;
            }

            // Отправляем уведомление
            Mail::send('vk-ads::emails.budget-exhausted', [
                'campaign' => $campaign,
                'account' => $account,
                'current_spend' => $event->currentSpend,
                'budget_limit' => $event->budgetLimit,
                'percentage_used' => ($event->currentSpend / $event->budgetLimit) * 100,
            ], function ($message) use ($notificationEmail, $campaign) {
                $message->to($notificationEmail)
                    ->subject("VK Ads: Бюджет кампании \"{$campaign->name}\" исчерпан");
            });

            Log::info("Budget alert sent for campaign {$campaign->id}");

        } catch (\Exception $e) {
            Log::error('Failed to send budget alert: '.$e->getMessage(), [
                'campaign_id' => $event->campaign->id,
                'exception' => $e->getTraceAsString(),
            ]);
        }
    }

    private function getNotificationEmail($account): ?string
    {
        // Логика получения email для уведомлений
        if ($account->isAgency() && $account->organization) {
            return $account->organization->email;
        }

        if ($account->isClient() && $account->contract) {
            // Можем получить email контрагента через договор
            $counterparty = $account->counterparty;

            return $counterparty?->email;
        }

        return config('vkads.notifications.default_email');
    }
}
