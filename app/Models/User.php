<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Ramsey\Uuid\Uuid;

/**
 * Класс ВебКреатива
 *
 * @OA\Schema(
 *      schema="User",
 *
 *              @OA\Property(property="id", type="integer", example="3"),
 *              @OA\Property(property="role_id", type="integer", example="3"),
 *              @OA\Property(property="name", type="string", example="Anton"),
 *              @OA\Property(property="email", type="string", example="test@test.com"),
 *              @OA\Property(property="email_verified_at", type="string", format="date-time", example=null),
 *              @OA\Property(
 *               property="permissions",
 *               type="array",
 *               description="Разрешенные сущности и действия над ними",
 *
 *               @OA\Items(
 *                   type="array",
 *                   @OA\Items()
 *               ),
 *              ),
 *
 *              @OA\Property(property="created_at", type="string", format="date-time", example="2024-05-06T18:23:37.000000Z"),
 *              @OA\Property(property="updated_at", type="string", format="date-time", example="2024-05-06T18:23:37.000000Z")
 * )
 *
 * @OA\Schema(
 *      schema="UserRequest",
 *
 *     @OA\Property(property="role_id", type="integer", example="3"),
 *               @OA\Property(property="name", type="string", example="Anton"),
 *               @OA\Property(property="email", type="string", example="test@test.com"),
 * )
 */
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $appends = ['permissions'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

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
        // Принудительное обновление кэшей через 1 час
        return 60 * 60 * 1;
    }

    public function getPermissionsAttribute(): array
    {
        // Здесь можно реализовать логику получения прав пользователя
        // Например, из базы данных или конфига
        $permissions = [
            'organisations' => 3,
            'channels' => 3,
            'mediaProducts' => 3,
            'salesModels' => 3,
            'advBlocks' => 3,
            'advBlockTypes' => 3,
            'advBlocksBroadcasting' => 3,
        ];

        return $permissions;
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array<string>
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
