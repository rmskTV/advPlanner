<?php

namespace Modules\Bitrix24\app\Enums;
enum Bitrix24FieldType
{
    case REQUISITE_GUID;
    case CONTACT_GUID;
    case USER_GUID;
    case CONTRACT_GUID_PREFIX;

    public function value(): string
    {
        return match($this) {
            self::REQUISITE_GUID => 'UF_CRM_GUID_1C',
            self::CONTACT_GUID => 'UF_CRM_GUID_1C',
            self::USER_GUID => 'UF_USR_1C_GUID',
            self::CONTRACT_GUID_PREFIX => 'UF_CRM_19_',
        };
    }

    public static function contractField(string $suffix): string
    {
        return self::CONTRACT_GUID_PREFIX->value() . $suffix;
    }
}
