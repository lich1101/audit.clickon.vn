<?php

namespace App\Services;

use App\Models\AuditRunItem;
use App\Models\WebsiteAuditUrlResult;

class WebsiteAuditUrlResultService
{
    public function upsertFromItem(AuditRunItem $item): WebsiteAuditUrlResult
    {
        $item->loadMissing('run');
        $run = $item->run;

        if (! $run) {
            throw new \RuntimeException('Audit run item is missing parent run.');
        }

        return WebsiteAuditUrlResult::query()->updateOrCreate(
            [
                'website_id' => $run->website_id,
                'target_url_hash' => hash('sha256', $item->target_url),
            ],
            [
                'target_url' => $item->target_url,
                'latest_audit_run_id' => $run->id,
                'latest_audit_run_item_id' => $item->id,
                'status' => $item->status,
                'page_title' => $item->page_title,
                'primary_keyword' => $item->primary_keyword,
                'category_name' => $item->category_name,
                'category_url' => $item->category_url,
                'category_match_reason' => $item->category_match_reason,
                'audit_score' => $item->audit_score,
                'audit_findings' => $item->audit_findings,
                'audit_recommendations' => $item->audit_recommendations,
                'content_revision_direction' => $item->content_revision_direction,
                'error_message' => $item->error_message,
                'ai_provider' => $run->step3_ai_provider ?: ($run->ai_provider ?? 'openai'),
                'ai_model' => $run->step3_ai_model ?: $run->ai_model,
                'audited_at' => $item->completed_at ?? now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(WebsiteAuditUrlResult $result): array
    {
        $recommendations = $result->audit_recommendations
            ? preg_split('/\r\n|\r|\n/', $result->audit_recommendations)
            : [];

        return [
            'targetUrl' => $result->target_url,
            'status' => $result->status,
            'pageTitle' => $result->page_title,
            'primaryKeyword' => $result->primary_keyword,
            'categoryName' => $result->category_name,
            'categoryUrl' => $result->category_url,
            'categoryMatchReason' => $result->category_match_reason,
            'auditScore' => $result->audit_score,
            'auditRecommendations' => array_values(array_filter(is_array($recommendations) ? $recommendations : [])),
            'contentRevisionDirection' => $result->content_revision_direction,
            'errorMessage' => $result->error_message,
            'aiProvider' => $result->ai_provider,
            'aiModel' => $result->ai_model,
            'auditedAt' => optional($result->audited_at)?->toIso8601String(),
            'updatedAt' => optional($result->updated_at)?->toIso8601String(),
        ];
    }
}
