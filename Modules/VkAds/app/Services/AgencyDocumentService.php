<?php

namespace Modules\VkAds\app\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Modules\Accounting\app\Models\Contract;
use Modules\Accounting\app\Models\Sale;
use Modules\Accounting\app\Models\SaleItem;
use Modules\VkAds\app\Models\VkAdsAdGroup;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgencyDocumentService
{
    // === ГЕНЕРАЦИЯ ДОКУМЕНТОВ ===

    public function generateActFromVkStats(Contract $contract, Carbon $periodStart, Carbon $periodEnd): Sale
    {
        // Получаем все группы объявлений по договору
        $adGroups = VkAdsAdGroup::whereHas('campaign.account', function ($query) use ($contract) {
            $query->where('contract_id', $contract->id);
        })->with(['statistics' => function ($query) use ($periodStart, $periodEnd) {
            $query->whereBetween('stats_date', [$periodStart, $periodEnd]);
        }, 'orderItem'])->get();

        // Создаем реализацию (акт)
        $sale = Sale::create([
            'number' => $this->generateSaleNumber(),
            'date' => now(),
            'organization_id' => $contract->organization_id,
            'counterparty_guid_1c' => $contract->counterparty_guid_1c,
            'contract_guid_1c' => $contract->guid_1c,
            'operation_type' => Sale::OPERATION_SALE_TO_CLIENT,
            'amount_includes_vat' => true,
        ]);

        $totalAmount = 0;
        $lineNumber = 1;

        foreach ($adGroups as $adGroup) {
            $stats = $adGroup->statistics;
            $totalSpend = $stats->sum('spend');

            if ($totalSpend > 0) {
                // Создаем строку реализации
                $saleItem = SaleItem::create([
                    'sale_id' => $sale->id,
                    'line_number' => $lineNumber++,
                    'product_guid_1c' => $adGroup->orderItem->product_guid_1c,
                    'product_name' => $adGroup->orderItem->product_name,
                    'quantity' => 1,
                    'unit_name' => 'услуга',
                    'amount' => $totalSpend,
                    'content' => $this->generateServiceDescription($adGroup, $periodStart, $periodEnd, $stats),
                ]);

                // Привязываем статистику к строке реализации
                $stats->each(function ($stat) use ($saleItem) {
                    $stat->update(['sale_item_id' => $saleItem->id]);
                });

                $totalAmount += $totalSpend;
            }
        }

        $sale->update(['amount' => $totalAmount]);

        return $sale;
    }

    public function generateInvoice(int $clientId, array $services): Sale
    {
        // Логика генерации счета
        // Это может быть предварительный счет до оказания услуг
        return new Sale; // Заглушка
    }

    public function generateReport(int $clientId, Carbon $periodStart, Carbon $periodEnd): array
    {
        $adGroups = VkAdsAdGroup::whereHas('campaign.account', function ($query) use ($clientId) {
            $query->where('id', $clientId);
        })->with(['statistics' => function ($query) use ($periodStart, $periodEnd) {
            $query->whereBetween('stats_date', [$periodStart, $periodEnd]);
        }, 'campaign', 'orderItem'])->get();

        $report = [
            'period' => [
                'start' => $periodStart->format('Y-m-d'),
                'end' => $periodEnd->format('Y-m-d'),
            ],
            'summary' => [
                'total_spend' => 0,
                'total_impressions' => 0,
                'total_clicks' => 0,
                'campaigns_count' => 0,
                'ad_groups_count' => $adGroups->count(),
            ],
            'campaigns' => [],
            'ad_groups' => [],
        ];

        $campaignStats = [];

        foreach ($adGroups as $adGroup) {
            $stats = $adGroup->statistics;
            $campaignId = $adGroup->campaign->id;

            // Агрегируем по кампаниям
            if (! isset($campaignStats[$campaignId])) {
                $campaignStats[$campaignId] = [
                    'id' => $campaignId,
                    'name' => $adGroup->campaign->name,
                    'spend' => 0,
                    'impressions' => 0,
                    'clicks' => 0,
                    'ad_groups_count' => 0,
                ];
            }

            $adGroupSpend = $stats->sum('spend');
            $adGroupImpressions = $stats->sum('impressions');
            $adGroupClicks = $stats->sum('clicks');

            $campaignStats[$campaignId]['spend'] += $adGroupSpend;
            $campaignStats[$campaignId]['impressions'] += $adGroupImpressions;
            $campaignStats[$campaignId]['clicks'] += $adGroupClicks;
            $campaignStats[$campaignId]['ad_groups_count']++;

            // Данные по группе объявлений
            $report['ad_groups'][] = [
                'id' => $adGroup->id,
                'name' => $adGroup->name,
                'campaign_name' => $adGroup->campaign->name,
                'order_item' => $adGroup->orderItem->product_name,
                'spend' => $adGroupSpend,
                'impressions' => $adGroupImpressions,
                'clicks' => $adGroupClicks,
                'ctr' => $adGroupImpressions > 0 ? ($adGroupClicks / $adGroupImpressions) * 100 : 0,
                'cpc' => $adGroupClicks > 0 ? $adGroupSpend / $adGroupClicks : 0,
            ];

            // Обновляем общую статистику
            $report['summary']['total_spend'] += $adGroupSpend;
            $report['summary']['total_impressions'] += $adGroupImpressions;
            $report['summary']['total_clicks'] += $adGroupClicks;
        }

        $report['campaigns'] = array_values($campaignStats);
        $report['summary']['campaigns_count'] = count($campaignStats);

        return $report;
    }

    // === УПРАВЛЕНИЕ ДОКУМЕНТАМИ ===

    public function getClientDocuments(int $clientId, ?string $type = null): Collection
    {
        $query = Sale::whereHas('items.vkAdsStatistics.adGroup.campaign.account', function ($q) use ($clientId) {
            $q->where('id', $clientId);
        })->with('items');

        if ($type) {
            // Можно добавить фильтрацию по типу документа, если будет поле type в Sale
            // $query->where('document_type', $type);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function downloadDocument(int $documentId): StreamedResponse
    {
        $sale = Sale::with('items.vkAdsStatistics')->findOrFail($documentId);

        return response()->streamDownload(function () use ($sale) {
            $this->generatePdfDocument($sale);
        }, "act_{$sale->number}.pdf", [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function sendDocumentByEmail(int $documentId, string $email): bool
    {
        $sale = Sale::with('items.vkAdsStatistics')->findOrFail($documentId);

        try {
            Mail::send('vk-ads::emails.document', ['sale' => $sale], function ($message) use ($email, $sale) {
                $message->to($email)
                    ->subject("Акт выполненных работ №{$sale->number}")
                    ->attach($this->generatePdfPath($sale));
            });

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to send document by email: '.$e->getMessage());

            return false;
        }
    }

    // === ОТЧЕТНОСТЬ ===

    public function generateClientReport(int $clientId, Carbon $periodStart, Carbon $periodEnd): array
    {
        return $this->generateReport($clientId, $periodStart, $periodEnd);
    }

    public function generateAgencyReport(int $agencyId, Carbon $periodStart, Carbon $periodEnd): array
    {
        // Получаем статистику по всем клиентам агентства
        $clientAccounts = \Modules\VkAds\app\Models\VkAdsAccount::where('account_type', 'client')->get();

        $agencyReport = [
            'period' => [
                'start' => $periodStart->format('Y-m-d'),
                'end' => $periodEnd->format('Y-m-d'),
            ],
            'summary' => [
                'clients_count' => $clientAccounts->count(),
                'total_spend' => 0,
                'total_impressions' => 0,
                'total_clicks' => 0,
                'total_campaigns' => 0,
            ],
            'clients' => [],
        ];

        foreach ($clientAccounts as $client) {
            $clientReport = $this->generateClientReport($client->id, $periodStart, $periodEnd);

            $agencyReport['clients'][] = [
                'client_id' => $client->id,
                'client_name' => $client->account_name,
                'contract' => $client->contract,
                'stats' => $clientReport['summary'],
            ];

            // Агрегируем общие показатели
            $agencyReport['summary']['total_spend'] += $clientReport['summary']['total_spend'];
            $agencyReport['summary']['total_impressions'] += $clientReport['summary']['total_impressions'];
            $agencyReport['summary']['total_clicks'] += $clientReport['summary']['total_clicks'];
            $agencyReport['summary']['total_campaigns'] += $clientReport['summary']['campaigns_count'];
        }

        return $agencyReport;
    }

    public function getClientBilling(int $clientId, Carbon $periodStart, Carbon $periodEnd): array
    {
        $acts = $this->getClientDocuments($clientId)
            ->whereBetween('date', [$periodStart, $periodEnd]);

        $billing = [
            'period' => [
                'start' => $periodStart->format('Y-m-d'),
                'end' => $periodEnd->format('Y-m-d'),
            ],
            'acts' => [],
            'summary' => [
                'acts_count' => $acts->count(),
                'total_amount' => 0,
                'paid_amount' => 0,
                'unpaid_amount' => 0,
            ],
        ];

        foreach ($acts as $act) {
            $actData = [
                'id' => $act->id,
                'number' => $act->number,
                'date' => $act->date->format('Y-m-d'),
                'amount' => $act->amount,
                'status' => 'unpaid', // Можно добавить поле статуса
                'services' => [],
            ];

            foreach ($act->items as $item) {
                $actData['services'][] = [
                    'name' => $item->product_name,
                    'amount' => $item->amount,
                    'content' => $item->content,
                ];
            }

            $billing['acts'][] = $actData;
            $billing['summary']['total_amount'] += $act->amount;

            // Здесь можно добавить логику определения оплаченных актов
            // if ($act->is_paid) {
            //     $billing['summary']['paid_amount'] += $act->amount;
            // }
        }

        $billing['summary']['unpaid_amount'] = $billing['summary']['total_amount'] - $billing['summary']['paid_amount'];

        return $billing;
    }

    // === ПРИВАТНЫЕ МЕТОДЫ ===

    private function generateSaleNumber(): string
    {
        $lastSale = Sale::orderBy('id', 'desc')->first();
        $nextNumber = $lastSale ? (int) substr($lastSale->number, -4) + 1 : 1;

        return 'ACT-'.date('Y').'-'.str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    private function generateServiceDescription(VkAdsAdGroup $adGroup, Carbon $periodStart, Carbon $periodEnd, Collection $stats): string
    {
        $totalImpressions = $stats->sum('impressions');
        $totalClicks = $stats->sum('clicks');
        $avgCTR = $totalImpressions > 0 ? ($totalClicks / $totalImpressions) * 100 : 0;

        return sprintf(
            'Рекламная кампания "%s" (группа объявлений "%s") за период %s - %s. '.
            'Показы: %d, Клики: %d, CTR: %.2f%%',
            $adGroup->campaign->name,
            $adGroup->name,
            $periodStart->format('d.m.Y'),
            $periodEnd->format('d.m.Y'),
            $totalImpressions,
            $totalClicks,
            $avgCTR
        );
    }

    private function generatePdfDocument(Sale $sale): void
    {
        // Здесь можно использовать библиотеку для генерации PDF, например DomPDF или TCPDF
        // Упрощенная реализация
        echo 'PDF content for sale #'.$sale->number;
    }

    private function generatePdfPath(Sale $sale): string
    {
        // Путь к сгенерированному PDF файлу
        return storage_path("app/documents/act_{$sale->number}.pdf");
    }
}
