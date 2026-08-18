<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndexProperty extends Model
{
    protected $fillable = [
        'user_uid',
        'code',
        'name',
        'site_url',
        'site_origin',
        'site_host',
        'gsc_property',
        'gcp_project_key',
        'sa_json_path',
        'daily_publish_quota',
        'daily_inspect_quota',
        'enabled',
        'is_owned',
        'permission_level',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_owned' => 'boolean',
            'daily_publish_quota' => 'integer',
            'daily_inspect_quota' => 'integer',
        ];
    }

    public function urls(): HasMany
    {
        return $this->hasMany(IndexUrl::class, 'property_id');
    }
}
