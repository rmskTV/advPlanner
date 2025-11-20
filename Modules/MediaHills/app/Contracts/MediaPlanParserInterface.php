<?php

namespace Modules\MediaHills\app\Contracts;

use Modules\MediaHills\app\DTOs\PlacementData;

interface MediaPlanParserInterface
{
    /**
     * Проверить, поддерживает ли парсер этот файл
     */
    public function supports(string $filePath): bool;

    /**
     * Распарсить медиаплан и вернуть массив размещений
     *
     * @return array{
     *   channel: string,
     *   start_date: string,
     *   end_date: string,
     *   placements: array<PlacementData>
     * }
     */
    public function parse(string $filePath): array;
}
