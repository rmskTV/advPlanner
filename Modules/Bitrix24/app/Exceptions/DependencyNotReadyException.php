<?php
// Modules/Bitrix24/app/Exceptions/DependencyNotReadyException.php

namespace Modules\Bitrix24\app\Exceptions;

class DependencyNotReadyException extends Bitrix24Exception
{
    public function isRetryable(): bool
    {
        return true; // Зависимость может появиться позже
    }
}
