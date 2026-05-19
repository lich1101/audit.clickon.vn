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
        private readonly SeoAiAuditService $seoAiAuditService,
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

        $activeRun = AuditRun::query()
            ->where('user_uid', $userUid)
            ->whereIn('status', ['queued', 'processing'])
            ->first();

        if ($activeRun) {
            throw new RuntimeException('Bạn đang có một audit run đang chạy. Mỗi tài khoản chỉ chạy một dự án audit tại một thời điểm.');
        }

        /** @var AuditRun $run */
        $run = DB::transaction(function () use ($userUid, $userEmail, $payload, $website): AuditRun {
            $targetUrls = array_values(array_unique($payload['targetUrls']));
            $categories = $payload['categories'] ?? [];
            $aiModel = trim((string) ($payload['aiModel'] ?? ''));

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
                'ai_provider' => $payload['aiProvider'] ?? 'openai',
                'ai_model' => $aiModel !== '' ? $aiModel : null,
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

    public function prepareCategoryContexts(AuditRun $run): void
    {
        if (is_array($run->category_contexts) && count($run->category_contexts) > 0) {
            return;
        }

        $maxChars = (int) config('services.audit.max_category_content_chars', 7000);
        $contexts = [];

        foreach ($run->categories ?? [] as $category) {
            $name = trim((string) ($category['name'] ?? ''));
            $url = trim((string) ($category['url'] ?? ''));

            if ($name === '' || $url === '') {
                continue;
            }

            $page = $this->contentExtractionService->extractOrFallback($url);

            $contexts[] = [
                'name' => $name,
                'url' => $url,
                'title' => $page['title'] ?? '',
                'metaDescription' => $page['metaDescription'] ?? '',
                'headings' => $page['headings'] ?? [],
                'metrics' => $page['metrics'] ?? [],
                'contentExcerpt' => mb_substr((string) ($page['content'] ?? ''), 0, $maxChars),
                'source' => $page['source'] ?? 'unknown',
                'error' => $page['extractionError'] ?? null,
            ];
        }

        $run->forceFill([
            'category_contexts' => $contexts,
        ])->save();

        $this->firestoreSyncService->syncRun($run->fresh());
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

    public function isRunCancelled(AuditRun $run): bool
    {
        $fresh = $run->fresh();

        if (! $fresh) {
            return true;
        }

        return $fresh->cancelled_at !== null || $fresh->status === 'failed';
    }

    public function stopRun(AuditRun $run, string $message = 'Audit run stopped by user.'): void
    {
        DB::transaction(function () use ($run, $message): void {
            $run = AuditRun::query()->lockForUpdate()->findOrFail($run->id);

            if (in_array($run->status, ['completed', 'failed', 'partial'], true)) {
                return;
            }

            $run->items()
                ->whereIn('status', ['queued', 'fetching', 'analyzing'])
                ->update([
                    'status' => 'failed',
                    'error_message' => $message,
                    'completed_at' => now(),
                ]);

            $items = $run->items()->get(['status']);
            $completed = $items->where('status', 'completed')->count();
            $failed = $items->where('status', 'failed')->count();
            $processed = $completed + $failed;

            $run->forceFill([
                'status' => 'failed',
                'last_error' => $message,
                'cancelled_at' => now(),
                'processed_urls' => $processed,
                'completed_urls' => $completed,
                'failed_urls' => $failed,
                'completed_at' => now(),
            ])->save();
        });

        $run = $run->fresh('items');
        $this->firestoreSyncService->syncRun($run);

        foreach ($run->items as $item) {
            $this->firestoreSyncService->syncItem($item);
        }
    }

    public function processItem(AuditRunItem $item): void
    {
        $run = $item->run()->firstOrFail();

        if ($this->isRunCancelled($run)) {
            return;
        }

        $item->forceFill([
            'status' => 'fetching',
            'error_message' => null,
        ])->save();
        $this->firestoreSyncService->syncItem($item->fresh('run'));

        if ($this->isRunCancelled($run->fresh())) {
            return;
        }

        $page = $this->contentExtractionService->extractOrFallback($item->target_url);

        $item->forceFill([
            'status' => 'analyzing',
            'extraction_source' => $page['source'] ?? null,
            'page_title' => $page['title'],
            'meta_description' => $page['metaDescription'],
            'canonical_url' => $page['canonicalUrl'],
            'extracted_headings' => $page['headings'],
            'extracted_metrics' => $page['metrics'],
            'content_excerpt' => $page['content'],
        ])->save();
        $this->firestoreSyncService->syncItem($item->fresh('run'));

        $run = $item->run()->firstOrFail();

        if ($this->isRunCancelled($run)) {
            return;
        }

        $analysis = $this->seoAiAuditService->analyze(
            page: $page,
            categoryContexts: $run->category_contexts ?? [],
            checklistText: $run->checklist_text,
            provider: $run->ai_provider ?? 'openai',
            model: $run->ai_model,
            auditRunId: $run->id,
        );

        if ($this->isRunCancelled($run->fresh())) {
            return;
        }

        $item->forceFill([
            'status' => 'completed',
            'primary_keyword' => $analysis['primaryKeyword'],
            'category_name' => $analysis['categoryName'],
            'category_url' => $analysis['categoryUrl'],
            'category_match_reason' => $analysis['categoryMatchReason'],
            'audit_score' => $analysis['auditScore'],
            'audit_findings' => implode("\n", $analysis['auditFindings']),
            'audit_recommendations' => implode("\n", $analysis['auditRecommendations']),
            'content_revision_direction' => $analysis['contentRevisionDirection'],
            'prompt_snapshots' => $analysis['promptSnapshots'],
            'completed_at' => now(),
        ])->save();

        $this->firestoreSyncService->syncItem($item->fresh('run'));
        $this->refreshRunProgress($item->run()->firstOrFail());
    }

    public function markItemFailed(AuditRunItem $item, string $message, bool $stopEntireRun = true): void
    {
        $item->forceFill([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();

        $this->firestoreSyncService->syncItem($item->fresh('run'));

        if ($stopEntireRun) {
            $this->stopRun($item->run()->firstOrFail(), $message);

            return;
        }

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
                'last_error' => $status === 'failed' && ! $run->last_error
                    ? 'All audit items failed.'
                    : $run->last_error,
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
    public function serializeRunSummary(AuditRun $run): array
    {
        return [
            'publicId' => $run->public_id,
            'websiteId' => $run->website_id,
            'websiteName' => $run->website_name,
            'websiteUrl' => $run->website_url,
            'targetUrls' => [],
            'categories' => [],
            'categoryContexts' => [],
            'checklistText' => null,
            'aiProvider' => $run->ai_provider ?? 'openai',
            'aiModel' => $run->ai_model,
            'status' => $run->status,
            'totalUrls' => $run->total_urls,
            'processedUrls' => $run->processed_urls,
            'completedUrls' => $run->completed_urls,
            'failedUrls' => $run->failed_urls,
            'startedAt' => optional($run->started_at)?->toIso8601String(),
            'completedAt' => optional($run->completed_at)?->toIso8601String(),
            'createdAt' => optional($run->created_at)?->toIso8601String(),
            'updatedAt' => optional($run->updated_at)?->toIso8601String(),
            'lastError' => $run->last_error,
            'items' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeItemSummary(AuditRunItem $item, ?AuditRun $run = null): array
    {
        $run ??= $item->relationLoaded('run') ? $item->run : null;
        $recommendations = $item->audit_recommendations
            ? preg_split('/\r\n|\r|\n/', $item->audit_recommendations)
            : [];

        return [
            'publicId' => $item->public_id,
            'auditRunId' => $run?->public_id,
            'websiteId' => $run?->website_id,
            'userId' => $run?->user_uid,
            'position' => $item->position,
            'targetUrl' => $item->target_url,
            'status' => $item->status,
            'pageTitle' => $item->page_title,
            'primaryKeyword' => $item->primary_keyword,
            'categoryName' => $item->category_name,
            'categoryUrl' => $item->category_url,
            'categoryMatchReason' => $item->category_match_reason,
            'auditScore' => $item->audit_score,
            'auditFindings' => [],
            'auditRecommendations' => array_values(array_filter(is_array($recommendations) ? $recommendations : [])),
            'contentRevisionDirection' => $item->content_revision_direction,
            'errorMessage' => $item->error_message,
            'updatedAt' => optional($item->updated_at)?->toIso8601String(),
        ];
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
            'categoryContexts' => $run->category_contexts ?? [],
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
            'createdAt' => optional($run->created_at)?->toIso8601String(),
            'updatedAt' => optional($run->updated_at)?->toIso8601String(),
            'lastError' => $run->last_error,
            'items' => $run->items->map(fn (AuditRunItem $item): array => [
                'publicId' => $item->public_id,
                'auditRunId' => $run->public_id,
                'websiteId' => $run->website_id,
                'userId' => $run->user_uid,
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
            ])->values()->all(),
        ];
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
                'systemPromptPreview' => mb_substr((string) ($snapshot['systemPrompt'] ?? ''), 0, 2500),
                'userPromptPreview' => mb_substr((string) ($snapshot['userPrompt'] ?? ''), 0, 2500),
            ];
        }

        return $compact;
    }
}
