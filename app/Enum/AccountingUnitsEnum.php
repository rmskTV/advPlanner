<?php

namespace App\Enum;

enum AccountingUnitsEnum: string
{
    const WORD = 'word';

    const CHAR = 'char';

    const PIECE = 'piece';

    const SECOND = 'second';

    const RELEASE = 'release';

    /**
     * Функция возвращает массив всех возможных значений для перечисления.
     *
     * @return string[]
     */
    public static function getValuesArray(): array
    {
        return [
            self::WORD,
            self::PIECE,
            self::SECOND,
            self::CHAR,
            self::RELEASE,
        ];
    }
}
