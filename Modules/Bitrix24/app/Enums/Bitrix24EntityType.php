<?php

// Modules/Bitrix24/app/Enums/Bitrix24EntityType.php

namespace Modules\Bitrix24\app\Enums;

enum Bitrix24EntityType: int
{
    case CONTACT = 3;
    case COMPANY = 4;
    case ADDRESS = 8;
    case INVOICE = 31;
    case CONTRACT = 1064;

    public function name(): string
    {
        return match ($this) {
            self::CONTACT => 'Contact',
            self::COMPANY => 'Company',
            self::ADDRESS => 'Address',
            self::INVOICE => 'Invoice',
            self::CONTRACT => 'Contract',
        };
    }
}
