<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ramsey\Uuid\Uuid;

class Model extends BaseModel
{
    use softDeletes;

    public static function booted(): void
    {
        static::creating(function ($model) {
            if (! isset($model->uuid)) {
                $model->uuid = Uuid::uuid1()->toString();
            }
        });
    }

    public static function cacheExpiried(): int
    {
        // Принудительное обновление кэшей через 48 часов ?? 1 час
        return 60 * 60 * 1;
    }
}
