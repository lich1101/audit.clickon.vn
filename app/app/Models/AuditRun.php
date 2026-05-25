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
        'category_contexts',
        'checklist_text',
        'ai_provider',
        'ai_model',
        'step2_ai_model',
        'step3_ai_model',
        'step2_formatter_provider',
        'step2_formatter_model',
        'step3_formatter_provider',
        'step3_formatter_model',
        'total_urls',
        'processed_urls',
        'completed_urls',
        'failed_urls',
        'started_at',
        'completed_at',
        'cancelled_at',
        'last_error',
        'ai_step_responses',
    ];

    protected function casts(): array
    {
        return [
            'target_urls' => 'array',
            'categories' => 'array',
            'category_contexts' => 'array',
            'ai_step_responses' => 'array',
            'total_urls' => 'integer',
            'processed_urls' => 'integer',
            'completed_urls' => 'integer',
            'failed_urls' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AuditRunItem::class);
    }
}
