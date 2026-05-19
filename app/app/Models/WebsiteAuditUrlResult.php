<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteAuditUrlResult extends Model
{
    protected $fillable = [
        'website_id',
        'target_url_hash',
        'target_url',
        'latest_audit_run_id',
        'latest_audit_run_item_id',
        'status',
        'page_title',
        'primary_keyword',
        'category_name',
        'category_url',
        'category_match_reason',
        'audit_score',
        'audit_findings',
        'audit_recommendations',
        'content_revision_direction',
        'error_message',
        'ai_provider',
        'ai_model',
        'audited_at',
    ];

    protected function casts(): array
    {
        return [
            'audit_score' => 'integer',
            'audited_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class, 'latest_audit_run_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AuditRunItem::class, 'latest_audit_run_item_id');
    }
}
