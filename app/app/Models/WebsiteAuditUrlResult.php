<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteAuditUrlResult extends Model
{
    public const BOARD_CONTENT_EXCERPT_LIMIT = 1200;

    /**
     * @return list<string>
     */
    public static function boardSummaryColumns(): array
    {
        return [
            'id',
            'website_id',
            'target_url',
            'latest_audit_run_id',
            'latest_audit_run_item_id',
            'status',
            'page_title',
            'meta_description',
            'canonical_url',
            'extracted_headings',
            'extracted_metrics',
            'content_source',
            'content_error',
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
            'updated_at',
        ];
    }

    protected $fillable = [
        'website_id',
        'target_url_hash',
        'target_url',
        'latest_audit_run_id',
        'latest_audit_run_item_id',
        'status',
        'page_title',
        'meta_description',
        'canonical_url',
        'extracted_headings',
        'extracted_metrics',
        'content_excerpt',
        'content_source',
        'content_error',
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
            'extracted_headings' => 'array',
            'extracted_metrics' => 'array',
            'audited_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<WebsiteAuditUrlResult>  $query
     */
    public function scopeForBoardSummary(Builder $query): Builder
    {
        return $query
            ->select(static::boardSummaryColumns())
            ->selectRaw('substr(content_excerpt, 1, ?) as content_excerpt', [static::BOARD_CONTENT_EXCERPT_LIMIT]);
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
