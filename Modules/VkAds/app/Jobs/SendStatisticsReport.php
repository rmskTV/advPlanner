<?php

namespace Modules\VkAds\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\VkAds\app\DTOs\StatisticsRequestDTO;
use Modules\VkAds\app\Services\VkAdsStatisticsService;

class SendStatisticsReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public StatisticsRequestDTO $request,
        public string $email
    ) {
        $this->onQueue('vk-ads-reports');
    }

    public function handle(VkAdsStatisticsService $statisticsService): void
    {
        try {
            // Генерируем отчет
            $fileName = "vk_ads_report_{$this->request->dateFrom->format('Y-m-d')}_{$this->request->dateTo->format('Y-m-d')}.csv";
            $filePath = "reports/{$fileName}";

            // Создаем CSV файл
            $csvContent = $this->generateCsvReport($statisticsService);
            Storage::disk('local')->put($filePath, $csvContent);

            // Отправляем email
            Mail::send('vk-ads::emails.statistics-report', [
                'period_start' => $this->request->dateFrom->format('d.m.Y'),
                'period_end' => $this->request->dateTo->format('d.m.Y'),
            ], function ($message) use ($fileName, $filePath) {
                $message->to($this->email)
                    ->subject('Отчет по статистике VK Ads')
                    ->attach(Storage::disk('local')->path($filePath), [
                        'as' => $fileName,
                        'mime' => 'text/csv',
                    ]);
            });

            // Удаляем временный файл
            Storage::disk('local')->delete($filePath);

        } catch (\Exception $e) {
            \Log::error('Failed to send statistics report: '.$e->getMessage(), [
                'email' => $this->email,
                'request' => $this->request->toArray(),
            ]);

            throw $e;
        }
    }

    private function generateCsvReport(VkAdsStatisticsService $statisticsService): string
    {
        $output = fopen('php://temp', 'w');

        // Заголовки
        fputcsv($output, [
            'Дата', 'Группа объявлений', 'Кампания', 'Показы', 'Клики',
            'Расход (руб.)', 'CTR (%)', 'CPC (руб.)', 'CPM (руб.)',
        ]);

        // Получаем статистику
        // Здесь нужно реализовать получение всех статистик по запросу
        // Упрощенная версия - можно расширить

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('Statistics report job failed', [
            'email' => $this->email,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
