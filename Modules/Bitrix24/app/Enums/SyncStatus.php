<?php
// Modules/Bitrix24/app/Enums/SyncStatus.php

namespace Modules\Bitrix24\app\Enums;

enum SyncStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case RETRY = 'retry';
    case PROCESSED = 'processed';
    case ERROR = 'error';
    case SKIPPED = 'skipped';

    public function canProcess(): bool
    {
        return in_array($this, [self::PENDING, self::RETRY]);
    }
}
