<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Wm\WmPackage\Database\Factories\UgcPoiFactory;
use Wm\WmPackage\Models\UgcPoi as BaseUgcPoi;

class UgcPoi extends BaseUgcPoi
{
    protected static function newFactory(): Factory
    {
        return UgcPoiFactory::new();
    }

    protected $fillable = [
        'user_id',
        'app_id',
        'name',
        'geometry',
        'properties',
        'created_by',
        'read_at',
    ];

    protected $casts = [
        'properties' => 'array',
        'read_at' => 'datetime',
    ];
}
