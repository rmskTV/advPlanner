<?php

namespace Modules\SalesModels\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Organisations\app\Models\Organisation;

/**
 * Класс Модели продаж
 *
 * @OA\Schema(
 *      schema="SalesModel",
 *
 *      @OA\Property(property="id", type="integer", example="14", description="ID модели продаж"),
 *      @OA\Property(property="uuid", type="string", example="14", description="UUID модели продаж"),
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название модели продаж"),
 *      @OA\Property(property="organisation_id", type="integer", example="12", description="ID организации, которой принадлежит модель продаж"),
 *      @OA\Property(property="contragent_id", type="integer", example="12", description="ID контрагента, владельца канала, с которым заключен агентский договор"),
 *      @OA\Property(property="percent", type="float", example="12.00", description="Процент вознаграждения (с продаж) организации"),
 *      @OA\Property(property="guarantee", type="float", example="10000.00", description="Гарантированный платеж владельцу канала"),
 * )
 *
 * @OA\Schema(
 *      schema="SalesModelRequest",
 *
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название модели продаж"),
 *      @OA\Property(property="organisation_id", type="integer", example="12", description="ID организации, которой принадлежит модель продаж"),
 *      @OA\Property(property="contragent_id", type="integer", example="12", description="ID контрагента, владельца канала, с которым заключен агентский договор"),
 *      @OA\Property(property="percent", type="float", example="12.00", description="Процент вознаграждения (с продаж) организации"),
 *      @OA\Property(property="guarantee", type="float", example="10000.00", description="Гарантированный платеж владельцу канала"),
 * )
 */
class SalesModel extends CatalogObject
{
    protected $fillable = ['name', 'organisation_id', 'percent', 'guarantee'];

    public function organisation(): HasOne
    {
        return $this->hasOne(Organisation::class, 'id', 'organisation_id');
    }
}
