<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditRunItem extends Model
{
    public const BOARD_TEXT_PREVIEW_LIMIT = 800;

    /**
     * Cột cần cho audit-board — không load prompt_snapshots/content_excerpt và các field dài.
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
            'page_title',
            'meta_description',
            'extracted_metrics',
            'primary_keyword',
            'category_name',
            'category_url',
            'audit_score',
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
            ->selectRaw('case when content_excerpt is not null and length(content_excerpt) > 0 then 1 else 0 end as has_content_excerpt')
            ->selectRaw('substr(content_error, 1, ?) as content_error', [static::BOARD_TEXT_PREVIEW_LIMIT])
            ->selectRaw('substr(audit_recommendations, 1, ?) as audit_recommendations', [static::BOARD_TEXT_PREVIEW_LIMIT])
            ->selectRaw('substr(content_revision_direction, 1, ?) as content_revision_direction', [static::BOARD_TEXT_PREVIEW_LIMIT])
            ->selectRaw('substr(error_message, 1, ?) as error_message', [static::BOARD_TEXT_PREVIEW_LIMIT]);
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
