<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use App\Models\ImageFile;
use App\Models\VideoFile;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VkAdsCreative extends CatalogObject
{
    protected $fillable = [
        'vk_creative_id', 'vk_ads_account_id', 'name', 'description',
        'creative_type', 'format', 'video_file_id', 'image_file_id',
        'width', 'height', 'duration'
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
    ];

    const TYPE_VIDEO = 'video';
    const TYPE_IMAGE = 'image';

    public function account(): BelongsTo
    {
        return $this->belongsTo(VkAdsAccount::class, 'vk_ads_account_id');
    }

    public function videoFile(): BelongsTo
    {
        return $this->belongsTo(VideoFile::class);
    }

    public function imageFile(): BelongsTo
    {
        return $this->belongsTo(ImageFile::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(VkAdsAd::class);
    }

    public function isVideo(): bool
    {
        return $this->creative_type === self::TYPE_VIDEO;
    }

    public function isImage(): bool
    {
        return $this->creative_type === self::TYPE_IMAGE;
    }
}
