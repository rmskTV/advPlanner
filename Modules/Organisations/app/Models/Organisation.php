<?php

namespace Modules\Organisations\app\Models;

use App\Models\CatalogObject;

/**
 * Класс канала
 *
 * @OA\Schema(
 * *      schema="Organisation",
 * *
 * *      @OA\Property(property="id", type="integer", example="14", description="ID организации"),
 * *      @OA\Property(property="uuid", type="string", example="14", description="UUID организации"),
 * *      @OA\Property(property="name", type="string", example="site.ru", description="Название организации"),
 * * )
 *
 * @OA\Schema(
 *       schema="OrganisationRequest",
 *
 *       @OA\Property(property="name", type="string", example="site.ru", description="Название организации"),
 *  )
 */
class Organisation extends CatalogObject
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['name'];
}
