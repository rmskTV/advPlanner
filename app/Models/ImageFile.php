<?php

namespace App\Models;

class ImageFile extends CatalogObject
{
    protected $fillable = [
        'original_name', 'hash', 'status', 'original_file_location',
        'optimized_file_location', 'thumbnail_file_location', 'width',
        'height', 'size', 'mime_type', 'format', 'exif_data', 'variants',
    ];

    protected $casts = [
        'exif_data' => 'array',
        'variants' => 'array',
    ];

    // === МЕТОДЫ ===

    public function getPublicUrl(): string
    {
        return \Storage::disk('public')->url($this->optimized_file_location ?: $this->original_file_location);
    }

    public function getThumbnailUrl(): ?string
    {
        return $this->thumbnail_file_location
            ? \Storage::disk('public')->url($this->thumbnail_file_location)
            : null;
    }

    public function getAspectRatio(): ?float
    {
        return $this->width && $this->height ? round($this->width / $this->height, 2) : null;
    }

    public function getDimensions(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'aspect_ratio' => $this->getAspectRatio(),
        ];
    }
}
