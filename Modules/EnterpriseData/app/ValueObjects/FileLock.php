<?php

namespace Modules\EnterpriseData\app\ValueObjects;

use Carbon\Carbon;

readonly class FileLock
{
    public function __construct(
        public string $fileName,
        public string $lockId,
        public Carbon $createdAt
    ) {}

    public function isExpired(int $timeoutSeconds = 300): bool
    {
        return $this->createdAt->addSeconds($timeoutSeconds)->isPast();
    }
}
