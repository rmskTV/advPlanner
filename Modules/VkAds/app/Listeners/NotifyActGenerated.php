<?php

namespace Modules\VkAds\app\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\VkAds\app\Events\ActGenerated;

class NotifyActGenerated implements ShouldQueue
{
    public function handle(ActGenerated $event): void
    {
        try {
            $sale = $event->sale;
            $contract = $event->contract;

            // Получаем email контрагента
            $counterparty = \Modules\Accounting\app\Models\Counterparty::where('guid_1c', $contract->counterparty_guid_1c)->first();

            if (! $counterparty || ! $counterparty->email) {
                Log::info("No email found for counterparty of contract {$contract->id}");

                return;
            }

            // Отправляем уведомление о сгенерированном акте
            Mail::send('vk-ads::emails.act-generated', [
                'sale' => $sale,
                'contract' => $contract,
                'counterparty' => $counterparty,
                'campaign_stats' => $event->campaignStats,
                'period' => [
                    'start' => $sale->items->min('created_at'),
                    'end' => $sale->items->max('created_at'),
                ],
            ], function ($message) use ($counterparty, $sale) {
                $message->to($counterparty->email)
                    ->subject("Акт выполненных работ №{$sale->number}")
                    ->attach($this->generateActPdf($sale));
            });

            Log::info("Act notification sent for sale {$sale->id}");

        } catch (\Exception $e) {
            Log::error('Failed to send act notification: '.$e->getMessage(), [
                'sale_id' => $event->sale->id,
                'exception' => $e->getTraceAsString(),
            ]);
        }
    }

    private function generateActPdf($sale): string
    {
        // Здесь генерируем PDF акта
        // Возвращаем путь к файлу
        return storage_path("app/acts/act_{$sale->number}.pdf");
    }
}
