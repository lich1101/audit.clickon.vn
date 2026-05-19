<?php

namespace App\Services;

use App\Models\AuditRun;
use App\Models\AuditRunItem;

class AuditRunFirestoreSyncService
{
    public function __construct(
        private readonly FirestoreService $firestoreService,
    ) {
    }

    public function syncRun(AuditRun $run): void
    {
        $this->firestoreService->upsertAuditRun([
            'publicId' => $run->public_id,
            'databaseId' => $run->id,
            'websiteId' => $run->website_id,
            'websiteName' => $run->website_name,
            'websiteUrl' => $run->website_url,
            'userId' => $run->user_uid,
            'userEmail' => $run->user_email,
            'targetUrls' => $run->target_urls ?? [],
            'categories' => $run->categories ?? [],
            'categoryContexts' => $this->compactCategoryContexts($run->category_contexts ?? []),
            'checklistText' => $run->checklist_text,
            'aiProvider' => $run->ai_provider ?? 'openai',
            'aiModel' => $run->ai_model,
            'status' => $run->status,
            'totalUrls' => $run->total_urls,
            'processedUrls' => $run->processed_urls,
            'completedUrls' => $run->completed_urls,
            'failedUrls' => $run->failed_urls,
            'startedAt' => optional($run->started_at)?->toIso8601String(),
            'completedAt' => optional($run->completed_at)?->toIso8601String(),
            'lastError' => $run->last_error,
            'createdAt' => optional($run->created_at)?->toIso8601String(),
            'updatedAt' => optional($run->updated_at)?->toIso8601String(),
        ]);
    }

    public function syncItem(AuditRunItem $item): void
    {
        $this->firestoreService->upsertAuditRunItem([
            'publicId' => $item->public_id,
            'databaseId' => $item->id,
            'auditRunId' => $item->run->public_id,
            'websiteId' => $item->run->website_id,
            'userId' => $item->run->user_uid,
            'position' => $item->position,
            'targetUrl' => $item->target_url,
            'status' => $item->status,
            'extractionSource' => $item->extraction_source,
            'pageTitle' => $item->page_title,
            'metaDescription' => $item->meta_description,
            'canonicalUrl' => $item->canonical_url,
            'headings' => $item->extracted_headings ?? [],
            'metrics' => $item->extracted_metrics ?? [],
            'primaryKeyword' => $item->primary_keyword,
            'categoryName' => $item->category_name,
            'categoryUrl' => $item->category_url,
            'categoryMatchReason' => $item->category_match_reason,
            'auditScore' => $item->audit_score,
            'auditFindings' => $item->audit_findings ? preg_split('/\r\n|\r|\n/', $item->audit_findings) : [],
            'auditRecommendations' => $item->audit_recommendations ? preg_split('/\r\n|\r|\n/', $item->audit_recommendations) : [],
            'contentRevisionDirection' => $item->content_revision_direction,
            'contentExcerpt' => $item->content_excerpt,
            'promptSnapshots' => $this->compactPromptSnapshots($item->prompt_snapshots ?? []),
            'errorMessage' => $item->error_message,
            'completedAt' => optional($item->completed_at)?->toIso8601String(),
            'createdAt' => optional($item->created_at)?->toIso8601String(),
            'updatedAt' => optional($item->updated_at)?->toIso8601String(),
        ]);
    }

    /**
     * @param  mixed  $snapshots
     * @return array<string, mixed>
     */
    private function compactPromptSnapshots(mixed $snapshots): array
    {
        if (! is_array($snapshots)) {
            return [];
        }

        $compact = [];

        foreach ($snapshots as $key => $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }

            $compact[(string) $key] = [
                'step' => $snapshot['step'] ?? (string) $key,
                'provider' => $snapshot['provider'] ?? null,
                'model' => $snapshot['model'] ?? null,
                'createdAt' => $snapshot['createdAt'] ?? null,
                'systemPromptPreview' => mb_substr((string) ($snapshot['systemPrompt'] ?? ''), 0, 1000),
                'userPromptPreview' => mb_substr((string) ($snapshot['userPrompt'] ?? ''), 0, 1000),
            ];
        }

        return $compact;
    }

    /**
     * @param  mixed  $contexts
     * @return array<int, array<string, mixed>>
     */
    private function compactCategoryContexts(mixed $contexts): array
    {
        if (! is_array($contexts)) {
            return [];
        }

        return array_values(array_map(
            fn (array $context): array => [
                'name' => $context['name'] ?? null,
                'url' => $context['url'] ?? null,
                'title' => $context['title'] ?? null,
                'source' => $context['source'] ?? null,
                'error' => $context['error'] ?? null,
                'contentExcerpt' => mb_substr((string) ($context['contentExcerpt'] ?? ''), 0, 1000),
            ],
            array_filter($contexts, 'is_array')
        ));
    }
}
