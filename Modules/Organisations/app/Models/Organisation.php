<?php

namespace Modules\Organisations\app\Models;

use App\Models\CatalogObject;

class Organisation extends CatalogObject
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['name'];
}
