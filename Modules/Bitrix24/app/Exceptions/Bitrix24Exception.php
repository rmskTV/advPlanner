<?php

namespace Modules\Bitrix24\app\Exceptions;

use Exception;

abstract class Bitrix24Exception extends Exception
{
    abstract public function isRetryable(): bool;
}
