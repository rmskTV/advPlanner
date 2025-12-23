<?php
// Modules/Bitrix24/app/Exceptions/ValidationException.php

namespace Modules\Bitrix24\app\Exceptions;

class ValidationException extends Bitrix24Exception
{
    public function isRetryable(): bool
    {
        return false; // Данные невалидны - retry не поможет
    }
}
