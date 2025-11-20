<?php

namespace Modules\MediaHills\app\DTOs;

class SpotAnalytics
{
    public int $spotNumber;

    public int $totalPlacements = 0;      // Всего выходов

    public int $foundPlacements = 0;      // Найдено данных

    public int $missingPlacements = 0;    // Нет данных

    public float $totalAudience = 0.0;    // Суммарная аудитория

    public array $placements = [];        // Детали по каждому выходу

    public function __construct(int $spotNumber)
    {
        $this->spotNumber = $spotNumber;
    }

    public function addPlacement(
        string $date,
        string $time,
        ?float $audienceValue,
        string $programName
    ): void {
        $this->totalPlacements++;

        if ($audienceValue !== null) {
            $this->foundPlacements++;
            $this->totalAudience += $audienceValue;
        } else {
            $this->missingPlacements++;
        }

        $this->placements[] = [
            'date' => $date,
            'time' => $time,
            'program' => $programName,
            'audience' => $audienceValue,
        ];
    }

    public function getAverageAudience(): float
    {
        return $this->foundPlacements > 0
            ? $this->totalAudience / $this->foundPlacements
            : 0.0;
    }
}
