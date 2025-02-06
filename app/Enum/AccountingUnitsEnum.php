<?php

namespace App\Enum;

enum AccountingUnitsEnum: string
{
    case WORD = 'word';
    case CHAR = 'char';
    case PIECE = 'piece';
    case SECOND = 'second';
    case RELEASE = 'release';



    public function label(): string
    {
        return match ($this) {
            self::WORD => 'Слово',
            self::CHAR => 'Символ',
            self::SECOND => 'Секунда',
            self::RELEASE => 'Выход',
            default => 'Штука', // self::PIECE
        };
    }



    /**
     * Функция возвращает массив всех возможных значений для перечисления.
     *
     * @return string[]
     */
    public static function getValuesArray(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());

    }


}
