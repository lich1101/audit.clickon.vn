<?php

namespace App\Services;

use App\Jobs\ProcessAuditRunJob;
use App\Models\AuditRun;
use App\Models\AuditRunItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AuditRunService
{
    public function __construct(
        private readonly SeoContentExtractionService $contentExtractionService,
        private readonly OpenAiSeoAuditService $openAiSeoAuditService,
        private readonly AuditRunFirestoreSyncService $firestoreSyncService,
        private readonly FirestoreService $firestoreService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createRun(string $userUid, string $userEmail, array $payload): AuditRun
    {
        $website = $this->firestoreService->getWebsite((string) $payload['websiteId']);

        if (! $website) {
            throw new RuntimeException('Website does not exist in Firestore.');
        }

        if (($website['userId'] ?? null) !== $userUid) {
            throw new AuthorizationException('You are not allowed to run audit for this website.');
        }

        /** @var AuditRun $run */
        $run = DB::transaction(function () use ($userUid, $userEmail, $payload, $website): AuditRun {
            $targetUrls = array_values(array_unique($payload['targetUrls']));
            $categories = $payload['categories'] ?? [];

            $run = AuditRun::query()->create([
                'public_id' => (string) Str::ulid(),
                'website_id' => $payload['websiteId'],
                'website_name' => $website['name'] ?? null,
                'website_url' => $website['url'] ?? null,
                'user_uid' => $userUid,
                'user_email' => $userEmail,
                'status' => 'queued',
                'target_urls' => $targetUrls,
                'categories' => $categories,
                'checklist_text' => $payload['checklistText'] ?? null,
                'total_urls' => count($targetUrls),
                'processed_urls' => 0,
                'completed_urls' => 0,
                'failed_urls' => 0,
            ]);

            foreach ($targetUrls as $index => $url) {
                $run->items()->create([
                    'public_id' => (string) Str::ulid(),
                    'position' => $index + 1,
                    'target_url' => $url,
                    'status' => 'queued',
                ]);
            }

            return $run->fresh('items');
        });

        $this->firestoreSyncService->syncRun($run);
        foreach ($run->items as $item) {
            $this->firestoreSyncService->syncItem($item);
        }

        ProcessAuditRunJob::dispatch($run->id);

        return $run;
    }

    public function markRunProcessing(AuditRun $run): void
    {
        $run->forceFill([
            'status' => 'processing',
            'started_at' => now(),
        ])->save();

        $this->firestoreSyncService->syncRun($run->fresh());
    }

    public function markRunFailed(AuditRun $run, string $message): void
    {
        $run->forceFill([
            'status' => 'failed',
            'last_error' => $message,
            'completed_at' => now(),
        ])->save();

        $this->firestoreSyncService->syncRun($run->fresh());
    }

    public function processItem(AuditRunItem $item): void
    {
        $item->forceFill([
            'status' => 'fetching',
            'error_message' => null,
        ])->save();
        $this->firestoreSyncService->syncItem($item->fresh('run'));

        $page = $this->contentExtractionService->extract($item->target_url);

        $item->forceFill([
            'status' => 'analyzing',
            'page_title' => $page['title'],
            'meta_description' => $page['metaDescription'],
            'canonical_url' => $page['canonicalUrl'],
            'extracted_headings' => $page['headings'],
            'extracted_metrics' => $page['metrics'],
            'content_excerpt' => $page['content'],
        ])->save();
        $this->firestoreSyncService->syncItem($item->fresh('run'));

        $analysis = $this->openAiSeoAuditService->analyze(
            page: $page,
            categories: $item->run->categories ?? [],
            checklistText: $item->run->checklist_text,
        );

        $item->forceFill([
            'status' => 'completed',
            'primary_keyword' => $analysis['primaryKeyword'],
            'category_name' => $analysis['categoryName'],
            'category_url' => $analysis['categoryUrl'],
            'audit_score' => $analysis['auditScore'],
            'audit_findings' => implode("\n", $analysis['auditFindings']),
            'audit_recommendations' => implode("\n", $analysis['auditRecommendations']),
            'content_revision_direction' => $analysis['contentRevisionDirection'],
            'completed_at' => now(),
        ])->save();

        $this->firestoreSyncService->syncItem($item->fresh('run'));
        $this->refreshRunProgress($item->run()->firstOrFail());
    }

    public function markItemFailed(AuditRunItem $item, string $message): void
    {
        $item->forceFill([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();

        $this->firestoreSyncService->syncItem($item->fresh('run'));
        $this->refreshRunProgress($item->run()->firstOrFail());
    }

    public function refreshRunProgress(AuditRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $run = AuditRun::query()->lockForUpdate()->findOrFail($run->id);
            $items = $run->items()->get(['status']);
            $completed = $items->where('status', 'completed')->count();
            $failed = $items->where('status', 'failed')->count();
            $processed = $completed + $failed;

            $status = $processed >= $run->total_urls
                ? ($completed === 0 ? 'failed' : ($failed > 0 ? 'partial' : 'completed'))
                : 'processing';

            $run->forceFill([
                'status' => $status,
                'processed_urls' => $processed,
                'completed_urls' => $completed,
                'failed_urls' => $failed,
                'completed_at' => $processed >= $run->total_urls ? now() : null,
                'last_error' => $status === 'failed' ? 'All audit items failed.' : $run->last_error,
            ])->save();
        });

        $this->firestoreSyncService->syncRun($run->fresh());
    }

    public function authorizeRead(Request $request, AuditRun $run): void
    {
        $uid = (string) $request->attributes->get('firebase_uid');
        $role = (string) $request->attributes->get('firebase_role', 'user');

        if ($role !== 'admin' && $uid !== $run->user_uid) {
            throw new AuthorizationException('You are not allowed to read this audit run.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRun(AuditRun $run): array
    {
        return [
            'publicId' => $run->public_id,
            'websiteId' => $run->website_id,
            'websiteName' => $run->website_name,
            'websiteUrl' => $run->website_url,
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
            'createdAt' => optional($run->created_at)?->toIso8601String(),
            'updatedAt' => optional($run->updated_at)?->toIso8601String(),
            'items' => $run->items->map(fn (AuditRunItem $item): array => [
                'publicId' => $item->public_id,
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
                'createdAt' => optional($item->created_at)?->toIso8601String(),
                'updatedAt' => optional($item->updated_at)?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
