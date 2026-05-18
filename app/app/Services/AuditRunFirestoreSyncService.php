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
            'checklistText' => $run->checklist_text,
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
            'pageTitle' => $item->page_title,
            'metaDescription' => $item->meta_description,
            'canonicalUrl' => $item->canonical_url,
            'headings' => $item->extracted_headings ?? [],
            'metrics' => $item->extracted_metrics ?? [],
            'primaryKeyword' => $item->primary_keyword,
            'categoryName' => $item->category_name,
            'categoryUrl' => $item->category_url,
            'auditScore' => $item->audit_score,
            'auditFindings' => $item->audit_findings ? preg_split('/\r\n|\r|\n/', $item->audit_findings) : [],
            'auditRecommendations' => $item->audit_recommendations ? preg_split('/\r\n|\r|\n/', $item->audit_recommendations) : [],
            'contentRevisionDirection' => $item->content_revision_direction,
            'contentExcerpt' => $item->content_excerpt,
            'errorMessage' => $item->error_message,
            'completedAt' => optional($item->completed_at)?->toIso8601String(),
            'createdAt' => optional($item->created_at)?->toIso8601String(),
            'updatedAt' => optional($item->updated_at)?->toIso8601String(),
        ]);
    }
}
