<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditRun extends Model
{
    public const WORKFLOW_STANDARD = 'standard';
    public const WORKFLOW_AUDIT_DEEP_RESEARCH = 'audit_deep_research';

    public const WORKFLOWS = [
        self::WORKFLOW_STANDARD,
        self::WORKFLOW_AUDIT_DEEP_RESEARCH,
    ];

    protected $fillable = [
        'public_id',
        'website_id',
        'website_name',
        'website_url',
        'user_uid',
        'user_email',
        'status',
        'workflow',
        'callback_url',
        'target_urls',
        'categories',
        'category_contexts',
        'checklist_text',
        'ai_provider',
        'ai_model',
        'step2_ai_provider',
        'step2_ai_model',
        'step3_ai_provider',
        'step3_ai_model',
        'step2_formatter_provider',
        'step2_formatter_model',
        'step3_formatter_provider',
        'step3_formatter_model',
        'deep_research_research_model',
        'deep_research_reasoning_model',
        'deep_research_formatter_provider',
        'deep_research_formatter_model',
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
