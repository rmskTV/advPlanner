<?php

namespace Modules\MediaHills\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\MediaHills\app\DTOs\SpotAnalytics;
use Modules\MediaHills\app\Models\TvAudienceData;
use Modules\MediaHills\app\Models\TvChannel;

class AudienceMatchingService
{
    /**
     * Анализ размещений и расчет аудитории
     */
    public function analyze(array $placements, string $channelName, ?int $year = null): array
    {
        // Находим канал
        $channel = TvChannel::where('name', $channelName)->first();

        if (! $channel) {
            throw new \Exception("Канал '$channelName' не найден в базе данных");
        }

        // Определяем год
        $year = $year ?? $this->detectYear($placements);

        Log::info("Анализ размещений для канала: {$channelName}, год: {$year}");

        // Группируем по номерам роликов
        $spotGroups = $this->groupBySpotNumber($placements);

        // Рассчитываем аудиторию для каждого ролика
        $analytics = [];

        foreach ($spotGroups as $spotNumber => $spotPlacements) {
            $analytics[$spotNumber] = $this->calculateSpotAudience(
                $spotNumber,
                $spotPlacements,
                $channel->id,
                $year
            );
        }

        // Сортируем по номеру ролика
        ksort($analytics);

        return $analytics;
    }

    /**
     * Группировка размещений по номерам роликов
     */
    private function groupBySpotNumber(array $placements): array
    {
        $groups = [];

        foreach ($placements as $placement) {
            $spotNumber = $placement->spotNumber;

            if (! isset($groups[$spotNumber])) {
                $groups[$spotNumber] = [];
            }

            $groups[$spotNumber][] = $placement;
        }

        return $groups;
    }

    /**
     * Расчет аудитории для одного ролика
     */
    private function calculateSpotAudience(
        int $spotNumber,
        array $placements,
        int $channelId,
        int $year
    ): SpotAnalytics {
        $analytics = new SpotAnalytics($spotNumber);

        foreach ($placements as $placement) {
            $datetime = $this->buildDateTime($placement->date, $placement->time, $year);

            if (! $datetime) {
                Log::warning('Не удалось построить datetime', [
                    'spot' => $spotNumber,
                    'date' => $placement->date,
                    'time' => $placement->time,
                ]);
                $analytics->addPlacement(
                    $placement->date,
                    $placement->time,
                    null,
                    $placement->programName
                );

                continue;
            }

            // Ищем аудиторию в БД
            $audienceData = TvAudienceData::where('channel_id', $channelId)
                ->where('datetime', $datetime)
                ->first();

            $audienceValue = $audienceData?->audience_value;

            $analytics->addPlacement(
                $placement->date,
                $placement->time,
                $audienceValue,
                $placement->programName
            );
        }

        return $analytics;
    }

    /**
     * Построение полной даты-времени
     */
    private function buildDateTime(string $date, string $time, int $year): ?Carbon
    {
        try {
            // Дата в формате DD.MM
            // Добавляем год
            $fullDate = $date.'.'.$year;

            // Парсим полную дату
            $dateObject = Carbon::createFromFormat('d.m.Y H:i', $fullDate.' '.$time);

            return $dateObject;
        } catch (\Exception $e) {
            Log::warning('Ошибка построения datetime', [
                'date' => $date,
                'time' => $time,
                'year' => $year,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Определение года из размещений или текущий
     */
    private function detectYear(array $placements): int
    {
        // TODO: можно извлекать из имени файла
        // Пока возвращаем текущий год
        return now()->year;
    }
}
