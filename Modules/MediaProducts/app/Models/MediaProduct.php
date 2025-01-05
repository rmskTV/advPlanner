<?php

namespace Modules\MediaProducts\app\Models;

use App\Models\CatalogObject;

/**
 * Класс модели медиапродукта
 *
 * @OA\Schema(
 *      schema="SMediaProduct",
 *
 *      @OA\Property(property="id", type="integer", example="14", description="ID мeдиапродукта"),
 *      @OA\Property(property="uuid", type="string", example="14", description="UUID мeдиапродукта"),
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название мeдиапродукта"),
 *      @OA\Property(property="organisation_id", type="integer", example="12", description="ID организации, которой принадлежит канал размещения мeдиапродукт (назначается автоматически)"),
 *      @OA\Property(property="channel_id", type="integer", example="12", description="ID канала размещения мeдиапродукта"),
 *      @OA\Property(property="start_time", type="timestamp", example="12:00:00", description="Время начала трансляции мeдиапродукта"),
 *      @OA\Property(property="duration", type="integer", example="15", description="Длительность (в минутах) мeдиапродукта"),
 * )
 */
class MediaProduct extends CatalogObject
{
    protected $fillable = ['name', 'channel_id', 'start_time', 'duration'];
}
