<?php

// Modules/Bitrix24/app/Exceptions/RateLimitException.php

namespace Modules\Bitrix24\app\Exceptions;

class RateLimitException extends Bitrix24Exception
{
    public function isRetryable(): bool
    {
        return true;
    }
}
