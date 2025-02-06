<?php

namespace Modules\AdvBlocks\app\Models;

use App\Enum\AccountingUnitsEnum;
use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Класс Модели типа рекламного блока
 *
 * @OA\Schema(
 *      schema="AdvBlockType",
 *
 *      @OA\Property(property="id", type="integer", example="14", description="ID модели продаж"),
 *      @OA\Property(property="uuid", type="string", example="14", description="UUID модели продаж"),
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название модели продаж"),
 *      @OA\Property(property="is_with_exact_time", type="boolean", example="1", description="Признак точного позиционирования блока по времени выхода"),
 *      @OA\Property(property="accounting_unit", type="string", example="piece", description="Единица учета рекламы",
 *        enum={"word","char", "piece", "second", "release"}
 *      ),
 * )
 *
 * @OA\Schema(
 *      schema="AdvBlockTypeRequest",
 *
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название модели продаж"),
 *       @OA\Property(property="is_with_exact_time", type="boolean", example="1", description="Признак точного позиционирования блока по времени выхода"),
 *       @OA\Property(property="accounting_unit", type="string", example="piece", description="Единица учета рекламы",
 *             enum={"word","char", "piece", "second", "release"}
 *       ),
 *      )
 */
class AdvBlockType extends CatalogObject
{
    protected $appends = [
        'accounting_unit_label',
    ];

    protected $fillable = ['name', 'is_with_exact_time', 'accounting_unit'];

    protected function accountingUnitLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => AccountingUnitsEnum::tryFrom($this->accounting_unit)?->label()
        );
    }

}
