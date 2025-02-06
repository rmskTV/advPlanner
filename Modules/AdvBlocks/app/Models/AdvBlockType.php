<?php

namespace Modules\AdvBlocks\app\Models;

use App\Models\CatalogObject;

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
    protected $fillable = ['name', 'is_with_exact_time', 'accounting_unit'];
}
