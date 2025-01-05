<?php

namespace Modules\Channels\app\Models;

use App\Models\CatalogObject;

class Channel extends CatalogObject
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['name', 'organisation_id'];
}
