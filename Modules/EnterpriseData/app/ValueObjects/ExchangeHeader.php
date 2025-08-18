<?php

namespace Modules\EnterpriseData\app\ValueObjects;

use Carbon\Carbon;

readonly class ExchangeHeader
{
    public function __construct(
        public string $format,
        public Carbon $creationDate,
        public string $exchangePlan,
        public string $fromNode,
        public string $toNode,
        public int    $messageNo,
        public int    $receivedNo,
        public array  $availableVersions,
        public array  $availableObjectTypes,
        public ?string $newFrom = null
    ) {}

    public function isConfirmationMessage(): bool
    {
        return $this->messageNo === 0 && $this->receivedNo > 0;
    }

    public function supportsObjectType(string $objectType, string $direction = 'receiving'): bool
    {
        foreach ($this->availableObjectTypes as $type) {
            if ($type['name'] === $objectType) {
                $supportedVersions = $type[$direction] ?? '';

                return $supportedVersions === '*' || ! empty($supportedVersions);
            }
        }

        return false;
    }

    public function getHighestAvailableVersion(): string
    {
        if (empty($this->availableVersions)) {
            return '1.6'; // Fallback версия
        }

        // Сортируем версии по убыванию
        $versions = $this->availableVersions;
        usort($versions, function ($a, $b) {
            return version_compare($b, $a);
        });

        return $versions[0];
    }

    public function hasConfirmation(): bool
    {
        return $this->receivedNo > 0;
    }

    public function hasNewFrom(): bool
    {
        return !empty($this->newFrom);
    }
}
