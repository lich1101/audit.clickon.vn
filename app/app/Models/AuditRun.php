<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditRun extends Model
{
    protected $fillable = [
        'public_id',
        'website_id',
        'website_name',
        'website_url',
        'user_uid',
        'user_email',
        'status',
        'target_urls',
        'categories',
        'checklist_text',
        'total_urls',
        'processed_urls',
        'completed_urls',
        'failed_urls',
        'started_at',
        'completed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'target_urls' => 'array',
            'categories' => 'array',
            'total_urls' => 'integer',
            'processed_urls' => 'integer',
            'completed_urls' => 'integer',
            'failed_urls' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AuditRunItem::class);
    }
}
