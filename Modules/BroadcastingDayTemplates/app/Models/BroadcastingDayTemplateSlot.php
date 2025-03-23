<?php

namespace Modules\BroadcastingDayTemplates\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Класс Модели слота суточного шаблона вещания
 *
 * @OA\Schema(
 *      schema="BroadcastingDayTemplateSlot",
 *
 *      @OA\Property(property="id", type="integer", example="14", description="ID слота"),
 *      @OA\Property(property="uuid", type="string", example="14", description="UUID слота"),
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название слота"),
 *      @OA\Property(property="comment", type="string", example="", description="Примечание"),
 *      @OA\Property(property="broadcasting_day_template_id", type="integer", example="1", description="ID шаблона, которому принадлежит слот"),
 *      @OA\Property(property="start", type="integer", example="1", description="Минута начала слота"),
 *      @OA\Property(property="end", type="integer", example="1", description="Минута окончания слота"),
 * )
 *
 * @OA\Schema(
 *      schema="BroadcastingDayTemplateSlotRequest",
 *
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название слота"),
 *      @OA\Property(property="comment", type="string", example="", description="Примечание"),
 *      @OA\Property(property="broadcasting_day_template_id", type="integer", example="1", description="ID шаблона, которому принадлежит слот"),
 *      @OA\Property(property="start", type="integer", example="1", description="Минута начала слота"),
 *      @OA\Property(property="end", type="integer", example="1", description="Минута окончания слота"),
 * )
 */
class BroadcastingDayTemplateSlot extends CatalogObject
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['name', 'comment', 'broadcasting_day_template_id', 'start', 'end'];
}
