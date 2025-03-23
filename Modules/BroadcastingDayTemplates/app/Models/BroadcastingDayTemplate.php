<?php

namespace Modules\BroadcastingDayTemplates\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Channels\app\Models\Channel;

/**
 * Класс Модели суточного шаблона вещания
 *
 * @OA\Schema(
 *      schema="BroadcastingDayTemplate",
 *
 *      @OA\Property(property="id", type="integer", example="14", description="ID шаблона"),
 *      @OA\Property(property="uuid", type="string", example="14", description="UUID шаблона"),
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название шаблона"),
 *      @OA\Property(property="comment", type="string", example="", description="Примечание"),
 *      @OA\Property(property="channel_id", type="integer", example="1", description="ID канала, которому принадлежит шаблон"),
 *      @OA\Property(property="start_hour", type="integer", example="1", description="Час, с которого начинается вещание шаблона"),
 * )
 *
 * @OA\Schema(
 *      schema="BroadcastingDayTemplateRequest",
 *
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название шаблона"),
 *      @OA\Property(property="comment", type="string", example="", description="Примечание"),
 *      @OA\Property(property="channel_id", type="integer", example="1", description="ID канала, которому принадлежит шаблон"),
 *      @OA\Property(property="start_hour", type="integer", example="1", description="Час, с которого начинается вещание шаблона"),
 * )
 */
class BroadcastingDayTemplate extends CatalogObject
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['name', 'comment', 'channel_id', 'start_hour'];

    public function channel(): HasOne
    {
        return $this->hasOne(Channel::class, 'id', 'channel_id');
    }

    public function broadcastingDayTemplateSlots(): HasMany
    {
        return $this->hasMany(BroadcastingDayTemplateSlot::class, 'broadcasting_day_template_id', 'id');
    }
}
