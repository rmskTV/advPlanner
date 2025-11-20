<?php

namespace Modules\MediaHills\app\DTOs;

class PlacementData
{
    public function __construct(
        public int $spotNumber,      // Номер ролика
        public string $date,         // Дата (25.09)
        public string $time,         // Время (06:05)
        public string $programName,  // Название программы
        public ?string $channelName = null,
    ) {}
}
