<?php

namespace Modules\AdvBlocks\app\Models;

use App\Models\CatalogObject;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Channels\app\Models\Channel;
use Modules\MediaProducts\app\Models\MediaProduct;
use Modules\SalesModels\app\Models\SalesModel;

/**
 * Класс Модели типа рекламного блока
 *
 * @OA\Schema(
 *      schema="AdvBlock",
 *
 *      @OA\Property(property="id", type="integer", example="14", description="ID рекламного блока"),
 *      @OA\Property(property="uuid", type="string", example="14", description="UUID рекламного блока"),
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название рекламного блока"),
 *      @OA\Property(property="comment", type="string", example="site.ru", description="Описание рекламного блока"),
 *      @OA\Property(property="sales_model_id", type="integer", example="14", description="ID Модели продаж для высчитывания рентабельности"),
 *      @OA\Property(property="adv_block_type_id", type="integer", example="14", description="ID  типа рекламного блока"),
 *      @OA\Property(property="media_product_id", type="integer", example="14", description="ID медиапродукта, которому принадлежит рекламный блок"),
 *      @OA\Property(property="channel_id", type="integer", example="14", description="ID канала, которому принадлежит рекламный блок"),
 *      @OA\Property(property="is_only_for_package", type="boolean", example="1", description="Блок только для пакетного размещения (прямое размещение недоступно)"),
 *      @OA\Property(property="size", type="float", example="10.00", description="Размер рекламного блока"),
 *      @OA\Property(property="channel", type="object", description="Подробное описание канала",
 *                   ref="#/components/schemas/Channel",
 *      ),
 *      @OA\Property(property="mediaProduct", type="object", description="Подробное описание канала",
 *                    ref="#/components/schemas/MediaProduct",
 *      ),
 *      @OA\Property(property="advBlockType", type="object", description="Подробное описание канала",
 *                     ref="#/components/schemas/AdvBlockType",
 *      ),
 *
 * )
 *
 * @OA\Schema(
 *      schema="AdvBlockRequest",
 *
 *      @OA\Property(property="name", type="string", example="site.ru", description="Название рекламного блока"),
 *      @OA\Property(property="comment", type="string", example="site.ru", description="Описание рекламного блока"),
 *      @OA\Property(property="adv_block_type_id", type="integer", example="14", description="ID  типа рекламного блока"),
 *      @OA\Property(property="media_product_id", type="integer", example="14", description="ID медиапродукта, которому принадлежит рекламный блок"),
 *      @OA\Property(property="sales_model_id", type="integer", example="14", description="ID Модели продаж для высчитывания рентабельности"),
 *      @OA\Property(property="channel_id", type="integer", example="14", description="ID канала, которому принадлежит рекламный блок"),
 *      @OA\Property(property="is_only_for_package", type="boolean", example="1", description="Блок только для пакетного размещения (прямое размещение недоступно)"),
 *      @OA\Property(property="size", type="float", example="10.00", description="Размер рекламного блока"),
 *      )
 */
class AdvBlock extends CatalogObject
{
    protected $fillable = ['name', 'comment', 'adv_block_type_id', 'channel_id', 'media_product_id', 'is_only_for_package', 'size', 'sales_model_id'];

    public function channel(): HasOne
    {
        return $this->hasOne(Channel::class, 'id', 'channel_id');
    }

    public function mediaProduct(): HasOne
    {
        return $this->hasOne(MediaProduct::class, 'id', 'media_product_id');
    }

    public function salesModel(): HasOne
    {
        return $this->hasOne(SalesModel::class, 'id', 'sales_model_id');
    }

    public function advBlockType(): HasOne
    {
        return $this->hasOne(AdvBlockType::class, 'id', 'adv_block_type_id');
    }
}
