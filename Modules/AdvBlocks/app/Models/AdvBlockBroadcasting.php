<?php

namespace Modules\AdvBlocks\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\AdvBlocks\app\Models\AdvBlock;
use Modules\Channels\app\Models\Channel;

/**
 * Класс Модели выхода рекламного блока
 *
 * @OA\Schema(
 *      schema="AdvBlockBroadcasting",
 *
 *      @OA\Property(property="id", type="integer", example="14", description="ID выхода рекламного блока"),
 *      @OA\Property(property="uuid", type="string", example="14", description="UUID выхода рекламного блока"),
 *      @OA\Property(property="adv_block_id", type="integer", example="14", description="ID рекламного блока"),
 *      @OA\Property(property="size", type="float", example="10.00", description="Размер рекламного блока"),
 *      @OA\Property(property="channel_id", type="integer", example="14", description="ID канала, которому принадлежит выход"),
 *      @OA\Property(property="broadcast_at", type="datetime", example="2025-01-25 03:05:16", description="Дата и время выхода"),
 *      @OA\Property(property="channel", type="object", description="Подробное описание канала",
 *                   ref="#/components/schemas/Channel",
 *      ),
 *      @OA\Property(property="advBlock", type="object", description="Подробное описание канала",
 *                     ref="#/components/schemas/AdvBlock",
 *      ),
 *
 * )
 *
 * @OA\Schema(
 *      schema="AdvBlockBroadcastingRequest",
 *
 *      @OA\Property(property="adv_block_id", type="integer", example="14", description="ID рекламного блока"),
 *      @OA\Property(property="broadcast_at", type="datetime", example="2025-01-25 03:05:16", description="Дата и время выхода"),
 *      @OA\Property(property="size", type="float", example="10.00", description="Размер рекламного блока"),
 *
 *      )
 */
class AdvBlockBroadcasting extends CatalogObject
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['adv_block_id', 'broadcast_at', 'channel_id', 'size'];

    public function channel(): HasOne
    {
        return $this->hasOne(Channel::class, 'id', 'channel_id');
    }

    public function advBlock(): HasOne
    {
        return $this->hasOne(AdvBlock::class, 'id', 'adv_block_id')->with('mediaProduct');
    }
}
