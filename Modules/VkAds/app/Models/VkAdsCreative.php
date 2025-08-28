<?php

namespace Modules\VkAds\app\Models;

use App\Models\CatalogObject;
use App\Models\ImageFile;
use App\Models\VideoFile;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VkAdsCreative extends CatalogObject
{
    protected $fillable = [
        'vk_creative_id', 'vk_ads_account_id', 'name', 'description',
        'creative_type', 'format', 'video_file_id', 'image_file_id',
        'media_variants', 'width', 'height', 'duration', 'file_size',
        'moderation_status', 'moderation_comment', 'moderated_at', 'vk_data',
    ];

    protected $casts = [
        'media_variants' => 'array',
        'vk_data' => 'array',
        'moderated_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    // === СВЯЗИ ===

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

    public function ads(): BelongsToMany
    {
        return $this->belongsToMany(VkAdsAd::class, 'vk_ads_ad_creatives')
            ->withPivot(['role', 'is_active', 'priority'])
            ->withTimestamps();
    }

    // === МЕТОДЫ ===

    public function isVideo(): bool
    {
        return $this->creative_type === self::TYPE_VIDEO;
    }

    public function isImage(): bool
    {
        return $this->creative_type === self::TYPE_IMAGE;
    }

    public function getPreviewUrl(): ?string
    {
        if ($this->isVideo() && $this->videoFile) {
            return $this->videoFile->getPreviewUrl();
        }

        if ($this->isImage() && $this->imageFile) {
            return $this->imageFile->getThumbnailUrl();
        }

        return null;
    }

    public function getPublicUrl(): ?string
    {
        if ($this->isVideo() && $this->videoFile) {
            return $this->videoFile->getPublicUrl();
        }

        if ($this->isImage() && $this->imageFile) {
            return $this->imageFile->getPublicUrl();
        }

        return null;
    }

    // НОВОЕ: Получение вариантов для разных соотношений сторон
    public function getVariantForAspectRatio(string $aspectRatio): ?array
    {
        $variants = $this->media_variants ?? [];

        foreach ($variants as $variant) {
            if ($variant['aspect_ratio'] === $aspectRatio) {
                return $variant;
            }
        }

        return null;
    }

    public function hasVariantForAspectRatio(string $aspectRatio): bool
    {
        return $this->getVariantForAspectRatio($aspectRatio) !== null;
    }

    public function getAvailableAspectRatios(): array
    {
        $ratios = [];

        // Основной файл
        if ($this->width && $this->height) {
            $ratios[] = $this->width.':'.$this->height;
        }

        // Варианты
        if ($this->media_variants) {
            foreach ($this->media_variants as $variant) {
                if (isset($variant['aspect_ratio'])) {
                    $ratios[] = $variant['aspect_ratio'];
                }
            }
        }

        return array_unique($ratios);
    }
}
