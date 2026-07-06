<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditRunItem extends Model
{
    public const BOARD_CONTENT_EXCERPT_LIMIT = 1200;

    /**
     * Cột cần cho audit-board / serializeItemSummary — không load prompt_snapshots (có thể rất lớn).
     *
     * @return list<string>
     */
    public static function boardSummaryColumns(): array
    {
        return [
            'id',
            'public_id',
            'audit_run_id',
            'position',
            'target_url',
            'status',
            'extraction_source',
            'content_source',
            'content_error',
            'page_title',
            'meta_description',
            'canonical_url',
            'extracted_headings',
            'extracted_metrics',
            'primary_keyword',
            'category_name',
            'category_url',
            'category_match_reason',
            'audit_score',
            'audit_findings',
            'audit_recommendations',
            'content_revision_direction',
            'error_message',
            'updated_at',
        ];
    }

    /**
     * @param  Builder<AuditRunItem>  $query
     */
    public function scopeForBoardSummary(Builder $query): Builder
    {
        return $query
            ->select(static::boardSummaryColumns())
            ->selectRaw('substr(content_excerpt, 1, ?) as content_excerpt', [static::BOARD_CONTENT_EXCERPT_LIMIT]);
    }

    protected $fillable = [
        'public_id',
        'audit_run_id',
        'position',
        'target_url',
        'status',
        'extraction_source',
        'content_source',
        'content_error',
        'page_title',
        'meta_description',
        'canonical_url',
        'primary_keyword',
        'category_name',
        'category_url',
        'category_match_reason',
        'audit_score',
        'audit_findings',
        'audit_recommendations',
        'content_revision_direction',
        'extracted_headings',
        'extracted_metrics',
        'content_excerpt',
        'prompt_snapshots',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'audit_score' => 'integer',
            'extracted_headings' => 'array',
            'extracted_metrics' => 'array',
            'prompt_snapshots' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class, 'audit_run_id');
    }
}
