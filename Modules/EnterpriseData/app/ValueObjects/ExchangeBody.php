<?php

namespace Modules\EnterpriseData\app\ValueObjects;

class ExchangeBody
{
    public function __construct(
        public readonly array $objects = []
    ) {}

    public function getObjectsByType(string $type): array
    {
        $filtered = array_filter($this->objects, function ($obj) use ($type) {
            return ($obj['type'] ?? '') === $type;
        });

        // Логируем для отладки
        \Log::debug('Filtering objects by type', [
            'requested_type' => $type,
            'total_objects' => count($this->objects),
            'filtered_count' => count($filtered),
            'available_types' => $this->getUniqueObjectTypes(),
        ]);

        return array_values($filtered); // Переиндексируем массив
    }

    public function getObjectsCount(): int
    {
        return count($this->objects);
    }

    public function isEmpty(): bool
    {
        return empty($this->objects);
    }

    public function getUniqueObjectTypes(): array
    {
        $types = array_map(fn ($obj) => $obj['type'] ?? 'Unknown', $this->objects);

        return array_unique($types);
    }
}
