<?php

namespace Modules\VkAds\app\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\VkAds\app\Models\VkAdsAccount;
use Modules\VkAds\app\Services\AgencyDocumentService;

class GenerateMonthlyActs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $month = null,
        public ?int $year = null
    ) {
        $this->onQueue('vk-ads-documents');

        if (! $this->month) {
            $this->month = Carbon::now()->subMonth()->month;
        }
        if (! $this->year) {
            $this->year = Carbon::now()->subMonth()->year;
        }
    }

    public function handle(AgencyDocumentService $documentService): void
    {
        $periodStart = Carbon::create($this->year, $this->month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        \Log::info("Generating monthly acts for period: {$periodStart->format('Y-m-d')} - {$periodEnd->format('Y-m-d')}");

        // Получаем все активные клиентские аккаунты
        $clientAccounts = VkAdsAccount::where('account_type', 'client')
            ->where('account_status', 'active')
            ->with('contract')
            ->get();

        $generatedActs = [];
        $errors = [];

        foreach ($clientAccounts as $account) {
            try {
                if (! $account->contract) {
                    \Log::warning("Client account {$account->id} has no contract, skipping");

                    continue;
                }

                // Проверяем, есть ли статистика за период
                $hasStatistics = $account->campaigns()
                    ->whereHas('adGroups.statistics', function ($query) use ($periodStart, $periodEnd) {
                        $query->whereBetween('stats_date', [$periodStart, $periodEnd])
                            ->where('spend', '>', 0);
                    })
                    ->exists();

                if (! $hasStatistics) {
                    \Log::info("No statistics found for account {$account->id} in period, skipping");

                    continue;
                }

                // Проверяем, не создан ли уже акт за этот период
                $existingAct = \Modules\Accounting\app\Models\Sale::where('contract_guid_1c', $account->contract->guid_1c)
                    ->whereBetween('date', [$periodStart, $periodEnd])
                    ->exists();

                if ($existingAct) {
                    \Log::info("Act already exists for account {$account->id} in period, skipping");

                    continue;
                }

                // Генерируем акт
                $act = $documentService->generateActFromVkStats($account->contract, $periodStart, $periodEnd);

                $generatedActs[] = [
                    'account_id' => $account->id,
                    'contract_id' => $account->contract->id,
                    'act_id' => $act->id,
                    'act_number' => $act->number,
                    'amount' => $act->amount,
                ];

                \Log::info("Generated act {$act->number} for account {$account->id}, amount: {$act->amount}");

            } catch (\Exception $e) {
                $error = "Failed to generate act for account {$account->id}: ".$e->getMessage();
                \Log::error($error);
                $errors[] = $error;
            }
        }

        \Log::info('Monthly acts generation completed', [
            'period' => "{$periodStart->format('Y-m-d')} - {$periodEnd->format('Y-m-d')}",
            'generated_count' => count($generatedActs),
            'errors_count' => count($errors),
            'generated_acts' => $generatedActs,
            'errors' => $errors,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('Monthly acts generation job failed', [
            'month' => $this->month,
            'year' => $this->year,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
