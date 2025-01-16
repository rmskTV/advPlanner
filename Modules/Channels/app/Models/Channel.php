<?php

namespace Modules\Channels\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Organisations\app\Models\Organisation;

/**
 * Класс канала
 *
 * @OA\Schema(
 *      schema="Channel",
 *
 *      @OA\Property(property="id", type="integer", example="14", description="ID канала"),
 *      @OA\Property(property="uuid", type="string", example="14", description="UUID канала"),
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название канала"),
 *      @OA\Property(property="organisation_id", type="integer", example="12", description="ID организации, которой принадлежит канал"),
 * )
 *
 * @OA\Schema(
 *       schema="ChannelRequest",
 *
 *       @OA\Property(property="name", type="string", example="site.ru", description="Название канала"),
 *       @OA\Property(property="organisation_id", type="integer", example="12", description="ID организации, которой принадлежит канал"),
 *  )
 */
class Channel extends CatalogObject
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['name', 'organisation_id'];

    public function organisation(): HasOne
    {
        return $this->hasOne(Organisation::class, 'id', 'organisation_id');
    }
}
