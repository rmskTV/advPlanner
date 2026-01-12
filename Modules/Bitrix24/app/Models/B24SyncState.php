<?php

namespace Modules\Bitrix24\app\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Отслеживание состояния синхронизации для каждого типа сущности B24
 *
 * Один экземпляр на тип (Company, Contact, Contract, Product, Invoice)
 * Хранит "точку остановки" для инкрементального импорта
 *
 * @property int $id
 * @property string $entity_type
 * @property Carbon|null $last_sync_at Время последнего успешного импорта
 * @property Carbon|null $last_b24_updated_at Самая свежая DATE_MODIFY из последней пачки
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class B24SyncState extends Model
{
    protected $fillable = [
        'entity_type',
        'last_sync_at',
        'last_b24_updated_at',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
        'last_b24_updated_at' => 'datetime',
    ];

    // Константы типов сущностей (порядок = порядок импорта)
    const ENTITY_COMPANY = 'Company';
    const ENTITY_CONTACT = 'Contact';
    const ENTITY_CONTRACT = 'Contract';
    const ENTITY_PRODUCT = 'Product';
    const ENTITY_INVOICE = 'Invoice';

    /**
     * Получить время последней синхронизации для типа сущности
     */
    public static function getLastSync(string $entityType): ?Carbon
    {
        $state = self::where('entity_type', $entityType)->first();

        return $state?->last_b24_updated_at; // ← Используем именно это время для фильтра!
    }

    /**
     * Обновить состояние после успешной синхронизации
     */
    public static function updateLastSync(
        string $entityType,
        Carbon $lastB24UpdatedAt
    ): void {
        self::updateOrCreate(
            ['entity_type' => $entityType],
            [
                'last_sync_at' => now(),
                'last_b24_updated_at' => $lastB24UpdatedAt,
            ]
        );
    }

    /**
     * Получить все состояния синхронизации
     */
    public static function getAllStates(): array
    {
        return self::all()->keyBy('entity_type')->toArray();
    }
}
