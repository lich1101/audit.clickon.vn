<?php

namespace App\Services;

use App\Models\AuditRunItem;
use App\Models\AuditRun;
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

        $targetUrlHash = hash('sha256', $item->target_url);
        $existing = WebsiteAuditUrlResult::query()
            ->where('website_id', $run->website_id)
            ->where('target_url_hash', $targetUrlHash)
            ->first();
        $hasFreshAuditPayload = $this->hasFreshAuditPayload($item);
        $provider = ($run->workflow ?? AuditRun::WORKFLOW_STANDARD) === AuditRun::WORKFLOW_AUDIT_DEEP_RESEARCH
            ? ($run->deep_research_reasoning_provider ?: 'openai')
            : ($run->step3_ai_provider ?: ($run->ai_provider ?? 'openai'));
        $model = ($run->workflow ?? AuditRun::WORKFLOW_STANDARD) === AuditRun::WORKFLOW_AUDIT_DEEP_RESEARCH
            ? ($run->deep_research_reasoning_model ?: (string) config('services.audit.deep_research_reasoning_model', config('services.openai.model', 'gpt-5.5')))
            : ($run->step3_ai_model ?: $run->ai_model);
        $status = $hasFreshAuditPayload
            ? $item->status
            : ($item->status === 'failed' ? 'failed' : ($existing?->status ?: $item->status));
        $errorMessage = $hasFreshAuditPayload
            ? $item->error_message
            : ($this->preferNewText($item->error_message, $existing?->error_message));
        $aiProvider = $hasFreshAuditPayload
            ? $provider
            : ($existing?->ai_provider ?: $provider);
        $aiModel = $hasFreshAuditPayload
            ? $model
            : ($existing?->ai_model ?: $model);
        $auditedAt = $hasFreshAuditPayload
            ? ($item->completed_at ?? now())
            : ($existing?->audited_at ?? ($item->status === 'failed' ? now() : $item->completed_at ?? now()));

        $payload = [
            'target_url' => $item->target_url,
            'latest_audit_run_id' => $run->id,
            'latest_audit_run_item_id' => $item->id,
            'status' => $status,
            'page_title' => $this->preferNewText($item->page_title, $existing?->page_title),
            'meta_description' => $this->preferNewText($item->meta_description, $existing?->meta_description),
            'canonical_url' => $this->preferNewText($item->canonical_url, $existing?->canonical_url),
            'extracted_headings' => $this->preferNewArray($item->extracted_headings, $existing?->extracted_headings),
            'extracted_metrics' => $this->preferNewArray($item->extracted_metrics, $existing?->extracted_metrics),
            'content_excerpt' => $this->preferNewText($item->content_excerpt, $existing?->content_excerpt),
            'content_source' => $this->preferNewText($item->content_source, $existing?->content_source),
            'content_error' => $this->preferNewText($item->content_error, $existing?->content_error),
            'primary_keyword' => $this->preferNewText($item->primary_keyword, $existing?->primary_keyword),
            'category_name' => $this->preferNewText($item->category_name, $existing?->category_name),
            'category_url' => $this->preferNewText($item->category_url, $existing?->category_url),
            'category_match_reason' => $this->preferNewText($item->category_match_reason, $existing?->category_match_reason),
            'audit_score' => $hasFreshAuditPayload
                ? $item->audit_score
                : $existing?->audit_score,
            'audit_findings' => $hasFreshAuditPayload
                ? $item->audit_findings
                : $existing?->audit_findings,
            'audit_recommendations' => $hasFreshAuditPayload
                ? $item->audit_recommendations
                : $existing?->audit_recommendations,
            'content_revision_direction' => $hasFreshAuditPayload
                ? $item->content_revision_direction
                : $existing?->content_revision_direction,
            'error_message' => $errorMessage,
            'ai_provider' => $aiProvider,
            'ai_model' => $aiModel,
            'audited_at' => $auditedAt,
        ];

        return WebsiteAuditUrlResult::query()->updateOrCreate(
            [
                'website_id' => $run->website_id,
                'target_url_hash' => $targetUrlHash,
            ],
            $payload,
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
            'metaDescription' => $result->meta_description,
            'canonicalUrl' => $result->canonical_url,
            'headings' => $result->extracted_headings ?? [],
            'metrics' => $result->extracted_metrics ?? [],
            'contentExcerpt' => $result->content_excerpt ? mb_substr($result->content_excerpt, 0, 1200) : null,
            'contentSource' => $result->content_source,
            'contentError' => $result->content_error,
            'readerUrl' => $this->readerUrlFor($result->target_url),
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

    private function hasFreshAuditPayload(AuditRunItem $item): bool
    {
        return $item->status === 'completed'
            && (
                $item->audit_score !== null
                || $this->filledText($item->audit_findings)
                || $this->filledText($item->audit_recommendations)
                || $this->filledText($item->content_revision_direction)
            );
    }

    private function preferNewText(?string $value, ?string $fallback): ?string
    {
        return $this->filledText($value) ? $value : $fallback;
    }

    private function preferNewArray(mixed $value, mixed $fallback): mixed
    {
        if (is_array($value) && $value !== []) {
            return $value;
        }

        return is_array($fallback) ? $fallback : $value;
    }

    private function filledText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function readerUrlFor(string $targetUrl): string
    {
        return rtrim((string) config('services.audit.jina_base_url', 'https://r.jina.ai/'), '/').'/'.$targetUrl;
    }
}
