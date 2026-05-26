<?php

namespace App\Services;

use App\Jobs\ProcessAuditRunJob;
use App\Jobs\ProcessAuditDeepResearchBatchJob;
use App\Jobs\ProcessAuditRunItemJob;
use App\Jobs\ProcessAuditRunStep2BatchJob;
use App\Jobs\ProcessAuditRunStep3BatchJob;
use App\Models\AuditRun;
use App\Models\AuditRunItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AuditRunService
{
    private const SOURCE_STEP2_RUNNING = 'url_only_batch_step2_running';
    private const SOURCE_STEP2_DONE = 'url_only_batch_step2_done';
    private const SOURCE_STEP3_RUNNING = 'url_only_batch_step3_running';
    private const SOURCE_COMPLETED = 'url_only_batch';
    private const SOURCE_DEEP_RESEARCH_RUNNING = 'audit_deep_research_running';
    private const SOURCE_DEEP_RESEARCH_COMPLETED = 'audit_deep_research';

    public function __construct(
        private readonly SeoContentExtractionService $contentExtractionService,
        private readonly SeoAiAuditService $seoAiAuditService,
        private readonly DeepResearchSeoAuditService $deepResearchSeoAuditService,
        private readonly AuditRunFirestoreSyncService $firestoreSyncService,
        private readonly WebsiteDataService $websiteDataService,
        private readonly AuditSettingsService $auditSettingsService,
        private readonly WebsiteAuditUrlResultService $urlResultService,
        private readonly CreditService $creditService,
        private readonly TokenBillingService $tokenBillingService,
        private readonly AuditRunCallbackService $callbackService,
    ) {
    }

    private function shouldSyncFirestore(): bool
    {
        return (bool) config('services.audit.firestore_sync', false);
    }

    private function syncRunIfEnabled(AuditRun $run): void
    {
        if ($this->shouldSyncFirestore()) {
            $this->firestoreSyncService->syncRun($run);
        }
    }

    private function syncItemIfEnabled(AuditRunItem $item): void
    {
        if ($this->shouldSyncFirestore()) {
            $this->firestoreSyncService->syncItem($item);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createRun(string $userUid, string $userEmail, array $payload): AuditRun
    {
        $website = $this->websiteDataService->getWebsite((string) $payload['websiteId']);

        if (! $website) {
            throw new RuntimeException('Website does not exist.');
        }

        if (($website['userId'] ?? null) !== $userUid) {
            throw new AuthorizationException('You are not allowed to run audit for this website.');
        }

        $settings = $this->auditSettingsService->getAuditSettings();
        $workflow = in_array(($settings['step3FlowMode'] ?? AuditRun::WORKFLOW_STANDARD), AuditRun::WORKFLOWS, true)
            ? (string) $settings['step3FlowMode']
            : AuditRun::WORKFLOW_STANDARD;
        $targetUrls = array_values(array_unique($payload['targetUrls']));

        if ($this->creditService->getBalance($userUid) <= 0) {
            throw new RuntimeException('Không đủ credit. Cần có credit trong tài khoản để khởi chạy audit; hệ thống sẽ trừ theo token AI thực tế sau mỗi lần gọi model.');
        }

        $activeRun = AuditRun::query()
            ->whereIn('status', ['queued', 'processing'])
            ->first();

        if ($activeRun) {
            throw new RuntimeException('Hệ thống đang có một audit run đang chạy. Mỗi lần chỉ chạy một dự án audit để tránh quá tải quota AI.');
        }

        /** @var AuditRun $run */
        $run = DB::transaction(function () use ($userUid, $userEmail, $payload, $website, $workflow, $settings): AuditRun {
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
                'workflow' => $workflow,
                'callback_url' => isset($payload['callbackUrl']) ? trim((string) $payload['callbackUrl']) ?: null : null,
                'target_urls' => $targetUrls,
                'categories' => $categories,
                'checklist_text' => $payload['checklistText'] ?? null,
                'ai_provider' => $settings['aiProvider'],
                'ai_model' => $settings['aiModel'],
                'step2_ai_provider' => $settings['step2AiProvider'] ?? $settings['aiProvider'],
                'step2_ai_model' => $settings['step2AiModel'] ?? $settings['aiModel'],
                'step3_ai_provider' => $settings['step3AiProvider'] ?? $settings['aiProvider'],
                'step3_ai_model' => $settings['step3AiModel'] ?? $settings['aiModel'],
                'step2_formatter_provider' => $settings['step2FormatterProvider'],
                'step2_formatter_model' => $settings['step2FormatterModel'],
                'step3_formatter_provider' => $settings['step3FormatterProvider'],
                'step3_formatter_model' => $settings['step3FormatterModel'],
                'deep_research_research_model' => $settings['deepResearchResearchModel'],
                'deep_research_reasoning_model' => $settings['deepResearchReasoningModel'],
                'deep_research_formatter_provider' => $settings['deepResearchFormatterProvider'],
                'deep_research_formatter_model' => $settings['deepResearchFormatterModel'],
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

        ProcessAuditRunJob::dispatch($run->id);

        return $run;
    }

    public function minimumCreditsPerUrl(string $provider, ?string $model): int
    {
        return $this->minimumCreditsPerRun($provider, $model);
    }

    public function minimumCreditsPerAiCall(string $provider, ?string $model): int
    {
        return $this->tokenBillingService->estimateMinimumCreditsForAiCall($provider, $model);
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function minimumCreditsPerRun(string $provider, ?string $model, ?int $totalUrls = null, ?array $settings = null): int
    {
        if ($totalUrls === null) {
            return $this->tokenBillingService->estimateMinimumCreditsForBatchRun($provider, $model);
        }

        $settings ??= $this->auditSettingsService->getAuditSettings();

        return $this->tokenBillingService->estimateMinimumCreditsForChunkedRun(
            provider: $provider,
            model: $model,
            totalUrls: $totalUrls,
            step2BatchSize: (int) ($settings['step2BatchSize'] ?? 60),
            step3BatchSize: (int) ($settings['step3BatchSize'] ?? 30),
        );
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

        $this->syncRunIfEnabled($run->fresh());
    }

    public function startChunkedBatchUrlOnly(AuditRun $run): void
    {
        $run = $run->fresh('items');

        if (! $run || $this->isRunCancelled($run)) {
            return;
        }

        if ($run->items()->count() === 0) {
            $this->markRunFailed($run, 'Audit run does not have any URL items.');

            return;
        }

        $this->dispatchStep2Batches($run);
    }

    public function startDeepResearchRun(AuditRun $run): void
    {
        $run = $run->fresh('items');

        if (! $run || $this->isRunCancelled($run)) {
            return;
        }

        if ($run->items()->count() === 0) {
            $this->markRunFailed($run, 'Audit run does not have any URL items.');

            return;
        }

        $this->prepareCategoryContexts($run);
        $this->dispatchStep2Batches($run);
    }

    public function dispatchStep2Batches(AuditRun $run): void
    {
        $state = DB::transaction(function () use ($run): array {
            $freshRun = AuditRun::query()->lockForUpdate()->find($run->id);

            if (! $freshRun || $this->isRunCancelled($freshRun)) {
                return ['chunks' => [], 'step2Complete' => false];
            }

            $settings = $this->auditSettingsService->getAuditSettings();
            $batchSize = max(1, (int) ($settings['step2BatchSize'] ?? 60));
            $maxParallel = max(1, (int) ($settings['maxParallelItems'] ?? 3));
            $activeItems = $freshRun->items()
                ->where('status', 'fetching')
                ->where('extraction_source', self::SOURCE_STEP2_RUNNING)
                ->count();
            $activeBatches = (int) ceil($activeItems / $batchSize);
            $slots = max(0, $maxParallel - $activeBatches);

            if ($slots === 0) {
                return ['chunks' => [], 'step2Complete' => false];
            }

            $pendingItems = $freshRun->items()
                ->where('status', 'queued')
                ->orderBy('position')
                ->limit($slots * $batchSize)
                ->get(['id']);

            $chunks = $pendingItems
                ->pluck('id')
                ->chunk($batchSize)
                ->map(fn ($chunk): array => $chunk->values()->all())
                ->values()
                ->all();

            foreach ($chunks as $chunk) {
                $freshRun->items()
                    ->whereIn('id', $chunk)
                    ->update([
                        'status' => 'fetching',
                        'extraction_source' => self::SOURCE_STEP2_RUNNING,
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
            }

            $hasQueued = $freshRun->items()->where('status', 'queued')->exists();
            $hasRunning = $freshRun->items()
                ->where('status', 'fetching')
                ->where('extraction_source', self::SOURCE_STEP2_RUNNING)
                ->exists();

            return [
                'chunks' => $chunks,
                'step2Complete' => ! $hasQueued && ! $hasRunning,
            ];
        });

        if (count($state['chunks']) > 0) {
            $this->syncRunIfEnabled($run->fresh());
        }

        foreach ($state['chunks'] as $chunk) {
            ProcessAuditRunStep2BatchJob::dispatch($run->id, $chunk);
        }

        if ($state['step2Complete'] && ($run->workflow ?? AuditRun::WORKFLOW_STANDARD) === AuditRun::WORKFLOW_AUDIT_DEEP_RESEARCH) {
            $this->dispatchDeepResearchBatches($run);

            return;
        }

        if ($state['step2Complete']) {
            $this->dispatchStep3Batches($run);
        }
    }

    public function dispatchStep3Batches(AuditRun $run): void
    {
        $state = DB::transaction(function () use ($run): array {
            $freshRun = AuditRun::query()->lockForUpdate()->find($run->id);

            if (! $freshRun || $this->isRunCancelled($freshRun)) {
                return ['chunks' => [], 'stageComplete' => false];
            }

            if ($freshRun->items()
                ->whereIn('status', ['queued', 'fetching'])
                ->exists()) {
                return ['chunks' => [], 'stageComplete' => false];
            }

            $settings = $this->auditSettingsService->getAuditSettings();
            $batchSize = max(1, (int) ($settings['step3BatchSize'] ?? 30));
            $maxParallel = max(1, (int) ($settings['maxParallelItems'] ?? 3));
            $activeItems = $freshRun->items()
                ->where('status', 'analyzing')
                ->where('extraction_source', self::SOURCE_STEP3_RUNNING)
                ->count();
            $activeBatches = (int) ceil($activeItems / $batchSize);
            $slots = max(0, $maxParallel - $activeBatches);

            if ($slots === 0) {
                return ['chunks' => [], 'stageComplete' => false];
            }

            $pendingItems = $freshRun->items()
                ->where('status', 'analyzing')
                ->where('extraction_source', self::SOURCE_STEP2_DONE)
                ->orderBy('position')
                ->limit($slots * $batchSize)
                ->get(['id']);

            $chunks = $pendingItems
                ->pluck('id')
                ->chunk($batchSize)
                ->map(fn ($chunk): array => $chunk->values()->all())
                ->values()
                ->all();

            foreach ($chunks as $chunk) {
                $freshRun->items()
                    ->whereIn('id', $chunk)
                    ->update([
                        'status' => 'analyzing',
                        'extraction_source' => self::SOURCE_STEP3_RUNNING,
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
            }

            $hasPending = $freshRun->items()
                ->where('status', 'analyzing')
                ->where('extraction_source', self::SOURCE_STEP2_DONE)
                ->exists();
            $hasRunning = $freshRun->items()
                ->where('status', 'analyzing')
                ->where('extraction_source', self::SOURCE_STEP3_RUNNING)
                ->exists();

            return [
                'chunks' => $chunks,
                'stageComplete' => ! $hasPending && ! $hasRunning,
            ];
        });

        if (count($state['chunks']) > 0) {
            $this->syncRunIfEnabled($run->fresh());
        }

        foreach ($state['chunks'] as $chunk) {
            ProcessAuditRunStep3BatchJob::dispatch($run->id, $chunk);
        }

        if ($state['stageComplete']) {
            $this->refreshRunProgress($run);
        }
    }

    public function dispatchDeepResearchBatches(AuditRun $run): void
    {
        $state = DB::transaction(function () use ($run): array {
            $freshRun = AuditRun::query()->lockForUpdate()->find($run->id);

            if (! $freshRun || $this->isRunCancelled($freshRun)) {
                return ['chunks' => [], 'stageComplete' => false];
            }

            if ($freshRun->items()
                ->whereIn('status', ['queued', 'fetching'])
                ->exists()) {
                return ['chunks' => [], 'stageComplete' => false];
            }

            $settings = $this->auditSettingsService->getAuditSettings();
            $batchSize = max(1, (int) ($settings['deepResearchBatchSize'] ?? 5));
            $maxParallel = max(1, (int) ($settings['maxParallelItems'] ?? 3));
            $activeItems = $freshRun->items()
                ->whereIn('status', ['fetching', 'analyzing'])
                ->where('extraction_source', self::SOURCE_DEEP_RESEARCH_RUNNING)
                ->count();
            $activeBatches = (int) ceil($activeItems / $batchSize);
            $slots = max(0, $maxParallel - $activeBatches);

            if ($slots === 0) {
                return ['chunks' => [], 'stageComplete' => false];
            }

            $pendingItems = $freshRun->items()
                ->where('status', 'analyzing')
                ->where('extraction_source', self::SOURCE_STEP2_DONE)
                ->orderBy('position')
                ->limit($slots * $batchSize)
                ->get(['id']);

            $chunks = $pendingItems
                ->pluck('id')
                ->chunk($batchSize)
                ->map(fn ($chunk): array => $chunk->values()->all())
                ->values()
                ->all();

            foreach ($chunks as $chunk) {
                $freshRun->items()
                    ->whereIn('id', $chunk)
                    ->update([
                        'status' => 'fetching',
                        'extraction_source' => self::SOURCE_DEEP_RESEARCH_RUNNING,
                        'error_message' => null,
                        'completed_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            $hasPending = $freshRun->items()
                ->where('status', 'analyzing')
                ->where('extraction_source', self::SOURCE_STEP2_DONE)
                ->exists();
            $hasRunning = $freshRun->items()
                ->whereIn('status', ['fetching', 'analyzing'])
                ->where('extraction_source', self::SOURCE_DEEP_RESEARCH_RUNNING)
                ->exists();

            return [
                'chunks' => $chunks,
                'stageComplete' => ! $hasPending && ! $hasRunning,
            ];
        });

        if (count($state['chunks']) > 0) {
            $this->syncRunIfEnabled($run->fresh());
        }

        foreach ($state['chunks'] as $chunk) {
            ProcessAuditDeepResearchBatchJob::dispatch($run->id, $chunk);
        }

        if ($state['stageComplete']) {
            $this->refreshRunProgress($run);
        }
    }

    public function dispatchDeepResearchItems(AuditRun $run): void
    {
        $this->dispatchDeepResearchBatches($run);
    }

    /**
     * @param  array<int, int>  $itemIds
     */
    public function processStep2Batch(AuditRun $run, array $itemIds): void
    {
        $run = $run->fresh();

        if (! $run || $this->isRunCancelled($run)) {
            return;
        }

        $items = $run->items()
            ->whereIn('id', $itemIds)
            ->orderBy('position')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $analysis = $this->seoAiAuditService->analyzeBatchKeywordCategoryUrlOnly(
            targetUrls: $items->pluck('target_url')->values()->all(),
            categories: $run->categories ?? [],
            provider: $this->stepAiProvider($run, 2),
            model: $this->stepAiModel($run, 2),
            formatterProvider: $run->step2_formatter_provider,
            formatterModel: $run->step2_formatter_model,
            auditRunId: $run->id,
            persistStep: $this->chunkStepKey('batch_keyword_category_mapping', $items),
        );

        if ($this->isRunCancelled($run->fresh())) {
            return;
        }

        $firstItem = $items->first();

        if ($firstItem) {
            foreach ($analysis['usageEvents'] ?? [] as $usage) {
                if (is_array($usage)) {
                    $this->tokenBillingService->chargeForAiCall($firstItem->fresh('run'), (string) ($usage['step'] ?? 'batch_keyword_category_mapping'), $usage);
                }
            }
        }

        $resultList = collect($analysis['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();
        $resultsByUrl = $resultList
            ->filter(fn (mixed $item): bool => is_array($item) && isset($item['targetUrl']))
            ->keyBy(fn (array $item): string => (string) $item['targetUrl']);

        foreach ($items->values() as $index => $item) {
            $result = $resultsByUrl->get($item->target_url) ?? $resultList->get($index);

            if (! is_array($result)) {
                $item->forceFill([
                    'status' => 'failed',
                    'extraction_source' => self::SOURCE_STEP2_DONE,
                    'error_message' => 'Batch AI bước 2 không trả kết quả cho URL này.',
                    'completed_at' => now(),
                ])->save();
                $this->syncItemIfEnabled($item->fresh('run'));
                $this->urlResultService->upsertFromItem($item->fresh('run'));

                continue;
            }

            $category = $this->resolveCategoryFields($result, $run->categories ?? [], $item->target_url);

            $item->forceFill([
                'status' => 'analyzing',
                'extraction_source' => self::SOURCE_STEP2_DONE,
                'primary_keyword' => $result['primaryKeyword'] ?? null,
                'category_name' => $category['name'],
                'category_url' => $category['url'],
                'category_match_reason' => $result['categoryMatchReason'] ?? null,
                'prompt_snapshots' => array_merge($item->prompt_snapshots ?? [], [
                    'keywordCategory' => $analysis['promptSnapshot'] ?? null,
                    'keywordCategoryFormatter' => $analysis['formatterPromptSnapshot'] ?? null,
                ]),
                'error_message' => null,
            ])->save();

            $this->syncItemIfEnabled($item->fresh('run'));
        }

        $this->refreshRunProgress($run);
        $this->dispatchStep2Batches($run);
    }

    /**
     * @param  array<int, int>  $itemIds
     */
    public function processStep3Batch(AuditRun $run, array $itemIds): void
    {
        $run = $run->fresh();

        if (! $run || $this->isRunCancelled($run)) {
            return;
        }

        $items = $run->items()
            ->whereIn('id', $itemIds)
            ->orderBy('position')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $keywordCategoryItems = $items->map(fn (AuditRunItem $item): array => [
            'targetUrl' => $item->target_url,
            'primaryKeyword' => $item->primary_keyword,
            'categoryName' => $item->category_name,
            'categoryUrl' => $item->category_url,
            'categoryMatchReason' => $item->category_match_reason,
        ])->values()->all();

        $analysis = $this->seoAiAuditService->analyzeBatchOnpageUrlOnly(
            targetUrls: $items->pluck('target_url')->values()->all(),
            categories: $run->categories ?? [],
            checklistText: $run->checklist_text,
            keywordCategoryItems: $keywordCategoryItems,
            provider: $this->stepAiProvider($run, 3),
            model: $this->stepAiModel($run, 3),
            formatterProvider: $run->step3_formatter_provider,
            formatterModel: $run->step3_formatter_model,
            auditRunId: $run->id,
            persistStep: $this->chunkStepKey('batch_onpage_audit', $items),
        );

        if ($this->isRunCancelled($run->fresh())) {
            return;
        }

        $firstItem = $items->first();

        if ($firstItem) {
            foreach ($analysis['usageEvents'] ?? [] as $usage) {
                if (is_array($usage)) {
                    $this->tokenBillingService->chargeForAiCall($firstItem->fresh('run'), (string) ($usage['step'] ?? 'batch_onpage_audit'), $usage);
                }
            }
        }

        $resultList = collect($analysis['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();
        $resultsByUrl = $resultList
            ->filter(fn (mixed $item): bool => is_array($item) && isset($item['targetUrl']))
            ->keyBy(fn (array $item): string => (string) $item['targetUrl']);

        foreach ($items->values() as $index => $item) {
            $result = $resultsByUrl->get($item->target_url) ?? $resultList->get($index);

            if (! is_array($result)) {
                $item->forceFill([
                    'status' => 'failed',
                    'error_message' => 'Batch AI bước 3 không trả kết quả cho URL này.',
                    'completed_at' => now(),
                ])->save();
                $this->syncItemIfEnabled($item->fresh('run'));
                $this->urlResultService->upsertFromItem($item->fresh('run'));

                continue;
            }

            $category = $this->resolveCategoryFields(
                $result,
                $run->categories ?? [],
                $item->target_url,
                $item->category_name,
                $item->category_url,
            );

            $item->forceFill([
                'status' => 'completed',
                'extraction_source' => self::SOURCE_COMPLETED,
                'primary_keyword' => $result['primaryKeyword'] ?? $item->primary_keyword,
                'category_name' => $category['name'],
                'category_url' => $category['url'],
                'category_match_reason' => $result['categoryMatchReason'] ?? $item->category_match_reason,
                'audit_score' => max(0, min(100, (int) ($result['auditScore'] ?? 0))),
                'audit_findings' => implode("\n", array_filter($result['auditFindings'] ?? [], 'is_string')),
                'audit_recommendations' => implode("\n", array_filter($result['auditRecommendations'] ?? [], 'is_string')),
                'content_revision_direction' => is_string($result['contentRevisionDirection'] ?? null) ? $result['contentRevisionDirection'] : null,
                'prompt_snapshots' => array_merge($item->prompt_snapshots ?? [], [
                    'onpageAudit' => $analysis['promptSnapshot'] ?? null,
                    'onpageAuditFormatter' => $analysis['formatterPromptSnapshot'] ?? null,
                ]),
                'error_message' => null,
                'completed_at' => now(),
            ])->save();

            $this->syncItemIfEnabled($item->fresh('run'));
            $this->urlResultService->upsertFromItem($item->fresh('run'));
        }

        $this->refreshRunProgress($run);
        $this->dispatchStep3Batches($run);
    }

    public function processBatchUrlOnly(AuditRun $run): void
    {
        $run = $run->fresh('items');

        if (! $run || $this->isRunCancelled($run)) {
            return;
        }

        $items = $run->items()->orderBy('position')->get();

        if ($items->isEmpty()) {
            $this->markRunFailed($run, 'Audit run does not have any URL items.');

            return;
        }

        $run->items()
            ->where('status', 'queued')
            ->update([
                'status' => 'analyzing',
                'extraction_source' => 'url_only_batch',
                'updated_at' => now(),
            ]);

        $freshRun = $run->fresh('items');

        foreach ($freshRun?->items ?? [] as $item) {
            $this->syncItemIfEnabled($item);
        }

        try {
            $analysis = $this->seoAiAuditService->analyzeBatchUrlOnly(
                targetUrls: $items->pluck('target_url')->values()->all(),
                categories: $run->categories ?? [],
                checklistText: $run->checklist_text,
                provider: $run->ai_provider ?? 'openai',
                model: $run->ai_model,
                auditRunId: $run->id,
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Audit run stopped.') {
                return;
            }

            $this->markBatchItemsFailed($run, $exception->getMessage());
            $this->markRunFailed($run, $exception->getMessage());

            return;
        }

        if ($this->isRunCancelled($run->fresh())) {
            return;
        }

        $firstItem = $items->first();

        if ($firstItem) {
            foreach ($analysis['usageEvents'] ?? [] as $usage) {
                if (! is_array($usage)) {
                    continue;
                }

                try {
                    $this->tokenBillingService->chargeForAiCall($firstItem->fresh('run'), (string) ($usage['step'] ?? 'batch_ai_call'), $usage);
                } catch (RuntimeException $exception) {
                    $this->markRunFailed($run, $exception->getMessage());

                    return;
                }
            }
        }

        $resultList = collect($analysis['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();
        $resultsByUrl = $resultList
            ->filter(fn (mixed $item): bool => is_array($item) && isset($item['targetUrl']))
            ->keyBy(fn (array $item): string => (string) $item['targetUrl']);

        foreach ($items->values() as $index => $item) {
            $result = $resultsByUrl->get($item->target_url) ?? $resultList->get($index);

            if (! is_array($result)) {
                $item->forceFill([
                    'status' => 'failed',
                    'error_message' => 'Batch AI response did not include this URL.',
                    'completed_at' => now(),
                ])->save();
                $this->syncItemIfEnabled($item->fresh('run'));
                $this->urlResultService->upsertFromItem($item->fresh('run'));

                continue;
            }

            $item->forceFill([
                'status' => 'completed',
                'extraction_source' => 'url_only_batch',
                'page_title' => $result['pageTitle'] ?? null,
                'primary_keyword' => $result['primaryKeyword'] ?? null,
                'category_name' => $result['categoryName'] ?? null,
                'category_url' => $result['categoryUrl'] ?? null,
                'category_match_reason' => $result['categoryMatchReason'] ?? null,
                'audit_score' => max(0, min(100, (int) ($result['auditScore'] ?? 0))),
                'audit_findings' => implode("\n", array_filter($result['auditFindings'] ?? [], 'is_string')),
                'audit_recommendations' => implode("\n", array_filter($result['auditRecommendations'] ?? [], 'is_string')),
                'content_revision_direction' => is_string($result['contentRevisionDirection'] ?? null) ? $result['contentRevisionDirection'] : null,
                'prompt_snapshots' => $analysis['promptSnapshots'] ?? [],
                'error_message' => null,
                'completed_at' => now(),
            ])->save();

            $this->syncItemIfEnabled($item->fresh('run'));
            $this->urlResultService->upsertFromItem($item->fresh('run'));
        }

        $this->refreshRunProgress($run);
    }

    public function markRunProcessing(AuditRun $run): void
    {
        $run->forceFill([
            'status' => 'processing',
            'started_at' => now(),
        ])->save();

        $this->syncRunIfEnabled($run->fresh());
    }

    public function markRunFailed(AuditRun $run, string $message): void
    {
        $items = $run->items()->get(['status']);
        $completed = $items->where('status', 'completed')->count();
        $failed = $items->where('status', 'failed')->count();

        $run->forceFill([
            'status' => 'failed',
            'last_error' => $message,
            'processed_urls' => $completed + $failed,
            'completed_urls' => $completed,
            'failed_urls' => $failed,
            'completed_at' => now(),
        ])->save();

        $this->syncRunIfEnabled($run->fresh());
    }

    public function markBatchItemsFailed(AuditRun $run, string $message): void
    {
        $itemIds = $run->items()
            ->whereIn('status', ['queued', 'fetching', 'analyzing'])
            ->pluck('id');

        if ($itemIds->isEmpty()) {
            return;
        }

        $run->items()
            ->whereIn('id', $itemIds)
            ->update([
                'status' => 'failed',
                'error_message' => $message,
                'completed_at' => now(),
            ]);

        foreach ($run->items()->whereIn('id', $itemIds)->get() as $item) {
            $this->syncItemIfEnabled($item->fresh('run'));
            $this->urlResultService->upsertFromItem($item->fresh('run'));
        }
    }

    /**
     * @param  array<int, int>  $itemIds
     */
    public function markBatchItemIdsFailed(AuditRun $run, array $itemIds, string $message): void
    {
        if ($itemIds === []) {
            return;
        }

        $run->items()
            ->whereIn('id', $itemIds)
            ->whereIn('status', ['queued', 'fetching', 'analyzing'])
            ->update([
                'status' => 'failed',
                'error_message' => $message,
                'completed_at' => now(),
            ]);

        foreach ($run->items()->whereIn('id', $itemIds)->get() as $item) {
            $this->syncItemIfEnabled($item->fresh('run'));
            $this->urlResultService->upsertFromItem($item->fresh('run'));
        }

        $this->refreshRunProgress($run);
    }

    /**
     * @param  array<int, int>  $itemIds
     */
    public function retryBatchItemIdsInSmallerChunks(AuditRun $run, array $itemIds, int $step, string $message): bool
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if (! in_array($step, [2, 3], true) || count($itemIds) <= 1 || ! $this->isRecoverableBatchShapeFailure($message)) {
            return false;
        }

        $status = $step === 2 ? 'fetching' : 'analyzing';
        $source = $step === 2 ? self::SOURCE_STEP2_RUNNING : self::SOURCE_STEP3_RUNNING;
        $chunkSize = $this->smallerRetryChunkSize(count($itemIds));

        $shouldRetry = DB::transaction(function () use ($run, $itemIds, $status, $source): bool {
            $freshRun = AuditRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($freshRun->cancelled_at !== null || in_array($freshRun->status, ['completed', 'failed', 'partial'], true)) {
                return false;
            }

            $freshRun->items()
                ->whereIn('id', $itemIds)
                ->update([
                    'status' => $status,
                    'extraction_source' => $source,
                    'error_message' => null,
                    'completed_at' => null,
                    'updated_at' => now(),
                ]);

            return true;
        });

        if (! $shouldRetry) {
            return false;
        }

        foreach (array_chunk($itemIds, $chunkSize) as $chunk) {
            if ($step === 2) {
                ProcessAuditRunStep2BatchJob::dispatch($run->id, array_values($chunk));

                continue;
            }

            ProcessAuditRunStep3BatchJob::dispatch($run->id, array_values($chunk));
        }

        $this->syncRunIfEnabled($run->fresh());

        return true;
    }

    /**
     * @param  array<int, int>  $itemIds
     */
    public function retryDeepResearchBatchItemIdsInSmallerChunks(AuditRun $run, array $itemIds, string $message): bool
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if (count($itemIds) <= 1 || ! $this->isRecoverableBatchShapeFailure($message)) {
            return false;
        }

        $chunkSize = $this->smallerRetryChunkSize(count($itemIds));

        $shouldRetry = DB::transaction(function () use ($run, $itemIds): bool {
            $freshRun = AuditRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($freshRun->cancelled_at !== null || in_array($freshRun->status, ['completed', 'failed', 'partial'], true)) {
                return false;
            }

            $freshRun->items()
                ->whereIn('id', $itemIds)
                ->update([
                    'status' => 'fetching',
                    'extraction_source' => self::SOURCE_DEEP_RESEARCH_RUNNING,
                    'error_message' => null,
                    'completed_at' => null,
                    'updated_at' => now(),
                ]);

            return true;
        });

        if (! $shouldRetry) {
            return false;
        }

        foreach (array_chunk($itemIds, $chunkSize) as $chunk) {
            ProcessAuditDeepResearchBatchJob::dispatch($run->id, array_values($chunk));
        }

        $this->syncRunIfEnabled($run->fresh());

        return true;
    }

    /**
     * @param  array<int, int>  $itemIds
     */
    public function markDeepResearchBatchItemIdsFailed(AuditRun $run, array $itemIds, string $message): void
    {
        $this->markBatchItemIdsFailed($run, $itemIds, $message);

        $freshRun = $run->fresh();

        foreach ($run->items()->whereIn('id', $itemIds)->get() as $item) {
            $this->notifyCallbackErrorSafely($freshRun ?: $run, $item->fresh('run') ?: $item, $message);
        }
    }

    private function isRecoverableBatchShapeFailure(string $message): bool
    {
        return str_contains($message, 'JSON thiếu dòng kết quả')
            || str_contains($message, 'JSON không có trường items hợp lệ');
    }

    private function smallerRetryChunkSize(int $itemCount): int
    {
        return max(1, min(20, (int) ceil($itemCount / 2)));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>  $categories
     * @return array{name: string|null, url: string|null}
     */
    private function resolveCategoryFields(array $result, array $categories, string $targetUrl, ?string $currentName = null, ?string $currentUrl = null): array
    {
        $name = $this->cleanCategoryValue($result['categoryName'] ?? $currentName ?? '');
        $url = $this->cleanCategoryValue($result['categoryUrl'] ?? $currentUrl ?? '');

        $exact = $this->findCategoryByNameOrUrl($categories, $name, $url);

        if ($exact) {
            return $exact;
        }

        if ($name !== '' || $url !== '') {
            return [
                'name' => $name !== '' ? $name : ($currentName ?: null),
                'url' => $url !== '' ? $url : ($currentUrl ?: null),
            ];
        }

        $keyword = trim((string) ($result['primaryKeyword'] ?? ''));
        $guessed = $this->guessCategoryFromUrl($categories, $targetUrl, $keyword);

        if ($guessed) {
            return $guessed;
        }

        return [
            'name' => $currentName ?: null,
            'url' => $currentUrl ?: null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array{name: string|null, url: string|null}|null
     */
    private function findCategoryByNameOrUrl(array $categories, string $name, string $url): ?array
    {
        $normalizedName = $this->normalizeCategoryText($name);
        $normalizedUrl = rtrim($url, '/');

        foreach ($categories as $category) {
            $categoryName = trim((string) ($category['name'] ?? ''));
            $categoryUrl = trim((string) ($category['url'] ?? ''));

            if ($categoryName === '' && $categoryUrl === '') {
                continue;
            }

            if ($normalizedName !== '' && $normalizedName === $this->normalizeCategoryText($categoryName)) {
                return ['name' => $categoryName ?: null, 'url' => $categoryUrl ?: null];
            }

            if ($normalizedUrl !== '' && rtrim($categoryUrl, '/') === $normalizedUrl) {
                return ['name' => $categoryName ?: null, 'url' => $categoryUrl ?: null];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array{name: string|null, url: string|null}|null
     */
    private function guessCategoryFromUrl(array $categories, string $targetUrl, string $keyword): ?array
    {
        $haystack = $this->normalizeCategoryText($targetUrl.' '.$keyword);
        $best = null;
        $bestScore = 0;

        foreach ($categories as $category) {
            $categoryName = trim((string) ($category['name'] ?? ''));
            $categoryUrl = trim((string) ($category['url'] ?? ''));
            $tokens = $this->categoryTokens($categoryName.' '.$categoryUrl);

            if ($tokens === []) {
                continue;
            }

            $score = 0;
            $phrase = implode(' ', $tokens);

            if ($phrase !== '' && str_contains($haystack, $phrase)) {
                $score += 8;
            }

            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    $score += 2;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['name' => $categoryName ?: null, 'url' => $categoryUrl ?: null];
            }
        }

        return $bestScore >= 4 ? $best : null;
    }

    /**
     * @return array<int, string>
     */
    private function categoryTokens(string $value): array
    {
        $stopWords = ['https', 'http', 'www', 'com', 'vn', 'html', 'php', 'thu', 'mua', 'gia', 'cao', 'tot', 'nhat', 'tai', 'cac', 'cho'];

        return array_values(array_unique(array_filter(
            explode(' ', $this->normalizeCategoryText($value)),
            fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, $stopWords, true),
        )));
    }

    private function normalizeCategoryText(string $value): string
    {
        $value = Str::ascii(Str::lower($value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '');
    }

    private function cleanCategoryValue(mixed $value): string
    {
        $text = trim((string) $value);
        $normalized = $this->normalizeCategoryText($text);

        return in_array($normalized, ['', 'null', 'none', 'na', 'n a'], true) || $text === '-' || $text === '—'
            ? ''
            : $text;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AuditRunItem>  $items
     */
    private function chunkStepKey(string $base, \Illuminate\Support\Collection $items): string
    {
        $positions = $items->pluck('position')->filter(fn ($position): bool => is_numeric($position))->map(fn ($position): int => (int) $position);

        if ($positions->isEmpty()) {
            return $base.'_'.Str::lower((string) Str::ulid());
        }

        return sprintf('%s_%03d_%03d', $base, $positions->min(), $positions->max());
    }

    private function stepAiProvider(AuditRun $run, int $step): string
    {
        $provider = $step === 2 ? $run->step2_ai_provider : $run->step3_ai_provider;

        return in_array($provider, ['openai', 'gemini', 'gemini_deep_research'], true)
            ? (string) $provider
            : (string) ($run->ai_provider ?: 'openai');
    }

    private function stepAiModel(AuditRun $run, int $step): ?string
    {
        $model = $step === 2 ? $run->step2_ai_model : $run->step3_ai_model;

        if (is_string($model) && trim($model) !== '') {
            return $model;
        }

        $stepProvider = $step === 2 ? $run->step2_ai_provider : $run->step3_ai_provider;

        return $stepProvider ? null : $run->ai_model;
    }

    /**
     * @param  array<int, array<string, mixed>>  $usageEvents
     * @return array{warning: string|null, cost: array<string, mixed>}
     */
    private function chargeUsageEventsSafely(AuditRunItem $item, array $usageEvents): array
    {
        $warnings = [];
        $events = [];
        $totalCredits = 0;

        foreach ($usageEvents as $usage) {
            if (! is_array($usage)) {
                continue;
            }

            try {
                $event = $this->tokenBillingService->chargeForAiCall(
                    $item->fresh('run'),
                    (string) ($usage['step'] ?? 'deep_research_ai_call'),
                    $usage,
                );

                $events[] = [
                    'step' => (string) ($usage['step'] ?? 'deep_research_ai_call'),
                    'provider' => (string) ($usage['provider'] ?? ''),
                    'model' => (string) ($usage['model'] ?? ''),
                    'inputTokens' => (int) ($usage['input_tokens'] ?? 0),
                    'outputTokens' => (int) ($usage['output_tokens'] ?? 0),
                    'totalTokens' => (int) ($usage['total_tokens'] ?? 0),
                    'creditsCharged' => (int) $event->credits_charged,
                ];
                $totalCredits += (int) $event->credits_charged;
            } catch (RuntimeException $exception) {
                $warnings[] = $exception->getMessage();
                report($exception);
            }
        }

        return [
            'warning' => $warnings !== [] ? implode(' | ', array_unique($warnings)) : null,
            'cost' => [
                'totalCreditsCharged' => $totalCredits,
                'events' => $events,
            ],
        ];
    }

    private function notifyCallbackErrorSafely(AuditRun $run, AuditRunItem $item, string $message): void
    {
        try {
            $this->callbackService->notifyError($run, $item, $message);
        } catch (RuntimeException $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function guessPrimaryKeywordSeed(array $page, string $targetUrl): string
    {
        $h1 = is_array($page['headings']['h1'] ?? null) ? implode(' ', $page['headings']['h1']) : '';
        $title = trim((string) ($page['title'] ?? ''));
        $candidate = trim($h1 !== '' ? $h1 : $title);

        if ($candidate !== '') {
            return $candidate;
        }

        $path = trim((string) parse_url($targetUrl, PHP_URL_PATH), '/');
        $path = str_replace(['-', '/'], ' ', $path);

        return trim($path);
    }

    private function itemStepSuffix(AuditRunItem $item): string
    {
        return sprintf('%03d', max(1, (int) $item->position));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AuditRunItem>  $items
     */
    private function chunkPositionSuffix(\Illuminate\Support\Collection $items): string
    {
        $positions = $items
            ->pluck('position')
            ->filter(fn ($position): bool => is_numeric($position))
            ->map(fn ($position): int => (int) $position)
            ->values();

        if ($positions->isEmpty()) {
            return Str::lower((string) Str::ulid());
        }

        return sprintf('%03d_%03d', $positions->min(), $positions->max());
    }

    private function persistDeepResearchWarning(AuditRun $run, string $step, string $message): void
    {
        $freshRun = $run->fresh();
        $responses = is_array($freshRun?->ai_step_responses) ? $freshRun->ai_step_responses : [];
        $responses[$step] = [
            'step' => $step,
            'stepLabel' => 'Deep Research warning',
            'status' => 'warning',
            'provider' => null,
            'model' => null,
            'rawTextPreview' => $message,
            'createdAt' => now()->toIso8601String(),
        ];

        if ($freshRun) {
            $freshRun->forceFill(['ai_step_responses' => $responses])->save();
        }
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
        $this->syncRunIfEnabled($run);
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
        $this->syncItemIfEnabled($item->fresh('run'));

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
        $this->syncItemIfEnabled($item->fresh('run'));

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

        foreach ($analysis['usageEvents'] ?? [] as $usage) {
            if (! is_array($usage)) {
                continue;
            }

            try {
                $this->tokenBillingService->chargeForAiCall($item->fresh('run'), (string) ($usage['step'] ?? 'ai_call'), $usage);
            } catch (RuntimeException $exception) {
                $this->markItemFailed($item, $exception->getMessage(), false);

                return;
            }
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

        $this->syncItemIfEnabled($item->fresh('run'));
        $this->urlResultService->upsertFromItem($item->fresh('run'));
        $run = $item->run()->firstOrFail();
        $this->refreshRunProgress($run);
        $this->dispatchNextItems($run->fresh('items'));
    }

    /**
     * @param  array<int, int>  $itemIds
     */
    public function processDeepResearchBatch(AuditRun $run, array $itemIds): void
    {
        $run = $run->fresh();

        if (! $run || $this->isRunCancelled($run)) {
            return;
        }

        $items = $run->items()
            ->whereIn('id', $itemIds)
            ->orderBy('position')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $batchPages = [];

        foreach ($items as $item) {
            $page = $this->contentExtractionService->extractOrFallback($item->target_url);
            $page['websiteUrl'] = $run->website_url;

            $primaryKeywordSeed = trim((string) ($item->primary_keyword ?: $this->guessPrimaryKeywordSeed($page, $item->target_url)));
            $seedCategory = $this->guessCategoryFromUrl(
                $run->categories ?? [],
                $item->target_url,
                $primaryKeywordSeed,
            );

            $item->forceFill([
                'status' => 'analyzing',
                'extraction_source' => self::SOURCE_DEEP_RESEARCH_RUNNING,
                'page_title' => $page['title'],
                'meta_description' => $page['metaDescription'],
                'canonical_url' => $page['canonicalUrl'],
                'extracted_headings' => $page['headings'],
                'extracted_metrics' => $page['metrics'],
                'content_excerpt' => $page['content'],
            ])->save();
            $this->syncItemIfEnabled($item->fresh('run'));

            $batchPages[] = [
                'targetUrl' => $item->target_url,
                'page' => $page,
                'primaryKeywordSeed' => $primaryKeywordSeed,
                'categoryNameSeed' => $item->category_name ?: ($seedCategory['name'] ?? null),
                'categoryUrlSeed' => $item->category_url ?: ($seedCategory['url'] ?? null),
            ];
        }

        if ($this->isRunCancelled($run->fresh())) {
            return;
        }

        $analysis = $this->deepResearchSeoAuditService->analyzeBatch(
            batchPages: $batchPages,
            categories: $run->categories ?? [],
            categoryContexts: $run->category_contexts ?? [],
            siteUrls: array_values($run->target_urls ?? []),
            checklistText: $run->checklist_text,
            auditRunId: $run->id,
            stepSuffix: $this->chunkPositionSuffix($items),
            researchModel: $run->deep_research_research_model,
            reasoningModel: $run->deep_research_reasoning_model,
            formatterProvider: $run->deep_research_formatter_provider,
            formatterModel: $run->deep_research_formatter_model,
        );

        if ($this->isRunCancelled($run->fresh())) {
            return;
        }

        $firstItem = $items->first();
        $billing = $firstItem
            ? $this->chargeUsageEventsSafely($firstItem, $analysis['usageEvents'] ?? [])
            : ['warning' => null, 'cost' => ['totalCreditsCharged' => 0, 'events' => []]];

        $cost = is_array($billing['cost'] ?? null)
            ? array_merge($billing['cost'], [
                'sharedAcrossBatch' => true,
                'batchItemCount' => $items->count(),
            ])
            : null;

        $resultList = collect($analysis['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();
        $resultsByUrl = $resultList
            ->filter(fn (mixed $item): bool => is_array($item) && isset($item['targetUrl']))
            ->keyBy(fn (array $item): string => (string) $item['targetUrl']);

        foreach ($items->values() as $index => $item) {
            $result = $resultsByUrl->get($item->target_url) ?? $resultList->get($index);

            if (! is_array($result)) {
                $item->forceFill([
                    'status' => 'failed',
                    'error_message' => 'Deep research batch không trả kết quả cho URL này.',
                    'completed_at' => now(),
                ])->save();
                $this->syncItemIfEnabled($item->fresh('run'));
                $this->urlResultService->upsertFromItem($item->fresh('run'));
                $this->notifyCallbackErrorSafely($run->fresh() ?: $run, $item->fresh('run') ?: $item, 'Deep research batch không trả kết quả cho URL này.');

                continue;
            }

            $category = $this->resolveCategoryFields(
                $result,
                $run->categories ?? [],
                $item->target_url,
                $item->category_name,
                $item->category_url,
            );

            $item->forceFill([
                'status' => 'completed',
                'extraction_source' => self::SOURCE_DEEP_RESEARCH_COMPLETED,
                'primary_keyword' => $result['primaryKeyword'] ?? $item->primary_keyword,
                'category_name' => $category['name'],
                'category_url' => $category['url'],
                'category_match_reason' => $result['categoryMatchReason'] ?? $item->category_match_reason,
                'audit_score' => max(0, min(100, (int) ($result['auditScore'] ?? 0))),
                'audit_findings' => implode("\n", array_filter($result['auditFindings'] ?? [], 'is_string')),
                'audit_recommendations' => implode("\n", array_filter($result['auditRecommendations'] ?? [], 'is_string')),
                'content_revision_direction' => is_string($result['contentRevisionDirection'] ?? null) ? $result['contentRevisionDirection'] : null,
                'prompt_snapshots' => array_merge($item->prompt_snapshots ?? [], $analysis['promptSnapshots'] ?? []),
                'error_message' => null,
                'completed_at' => now(),
            ])->save();

            $this->syncItemIfEnabled($item->fresh('run'));
            $this->urlResultService->upsertFromItem($item->fresh('run'));

            $callbackWarning = is_string($billing['warning'] ?? null) ? $billing['warning'] : null;

            try {
                $this->callbackService->notifySuccess(
                    $run->fresh() ?: $run,
                    $item->fresh('run') ?: $item,
                    is_array($result['researchData'] ?? null) ? $result['researchData'] : null,
                    is_array($analysis['modelUsed'] ?? null) ? $analysis['modelUsed'] : null,
                    $cost,
                    $callbackWarning !== '' ? $callbackWarning : null,
                );
            } catch (RuntimeException $exception) {
                $callbackWarning = trim(implode(' | ', array_filter([
                    $callbackWarning,
                    $exception->getMessage(),
                ])));
            }

            if (is_string($callbackWarning) && $callbackWarning !== '') {
                $this->persistDeepResearchWarning($run, 'deep_research_warning_'.$this->itemStepSuffix($item), $callbackWarning);
            }
        }

        $this->refreshRunProgress($run);
        $this->dispatchDeepResearchBatches($run->fresh('items'));
    }

    public function processDeepResearchItem(AuditRunItem $item): void
    {
        $run = $item->run()->firstOrFail();

        if ($this->isRunCancelled($run)) {
            return;
        }

        $item->forceFill([
            'status' => 'fetching',
            'extraction_source' => self::SOURCE_DEEP_RESEARCH_RUNNING,
            'error_message' => null,
            'completed_at' => null,
        ])->save();
        $this->syncItemIfEnabled($item->fresh('run'));

        $page = $this->contentExtractionService->extractOrFallback($item->target_url);
        $page['websiteUrl'] = $run->website_url;

        $item->forceFill([
            'status' => 'analyzing',
            'extraction_source' => self::SOURCE_DEEP_RESEARCH_RUNNING,
            'page_title' => $page['title'],
            'meta_description' => $page['metaDescription'],
            'canonical_url' => $page['canonicalUrl'],
            'extracted_headings' => $page['headings'],
            'extracted_metrics' => $page['metrics'],
            'content_excerpt' => $page['content'],
        ])->save();
        $this->syncItemIfEnabled($item->fresh('run'));

        if ($this->isRunCancelled($run->fresh())) {
            return;
        }

        $seedCategory = $this->guessCategoryFromUrl(
            $run->categories ?? [],
            $item->target_url,
            $this->guessPrimaryKeywordSeed($page, $item->target_url),
        );

        try {
            $analysis = $this->deepResearchSeoAuditService->analyze(
                page: $page,
                categories: $run->categories ?? [],
                categoryContexts: $run->category_contexts ?? [],
                siteUrls: array_values(array_filter($run->target_urls ?? [], fn (string $url): bool => $url !== $item->target_url)),
                checklistText: $run->checklist_text,
                auditRunId: $run->id,
                stepSuffix: $this->itemStepSuffix($item),
                primaryKeywordSeed: trim((string) ($item->primary_keyword ?: $this->guessPrimaryKeywordSeed($page, $item->target_url))),
                categoryNameSeed: $item->category_name ?: ($seedCategory['name'] ?? null),
                categoryUrlSeed: $item->category_url ?: ($seedCategory['url'] ?? null),
                researchModel: $run->deep_research_research_model,
                reasoningModel: $run->deep_research_reasoning_model,
                formatterProvider: $run->deep_research_formatter_provider,
                formatterModel: $run->deep_research_formatter_model,
            );
        } catch (\Throwable $exception) {
            $this->markItemFailed($item, $exception->getMessage(), false);
            $this->notifyCallbackErrorSafely($run->fresh() ?: $run, $item->fresh('run') ?: $item, $exception->getMessage());

            return;
        }

        if ($this->isRunCancelled($run->fresh())) {
            return;
        }

        $billing = $this->chargeUsageEventsSafely($item, $analysis['usageEvents'] ?? []);

        $item->forceFill([
            'status' => 'completed',
            'extraction_source' => self::SOURCE_DEEP_RESEARCH_COMPLETED,
            'primary_keyword' => $analysis['primaryKeyword'],
            'category_name' => $analysis['categoryName'],
            'category_url' => $analysis['categoryUrl'],
            'category_match_reason' => $analysis['categoryMatchReason'],
            'audit_score' => $analysis['auditScore'],
            'audit_findings' => implode("\n", $analysis['auditFindings']),
            'audit_recommendations' => implode("\n", $analysis['auditRecommendations']),
            'content_revision_direction' => $analysis['contentRevisionDirection'],
            'prompt_snapshots' => array_merge($item->prompt_snapshots ?? [], $analysis['promptSnapshots'] ?? []),
            'error_message' => null,
            'completed_at' => now(),
        ])->save();

        $this->syncItemIfEnabled($item->fresh('run'));
        $this->urlResultService->upsertFromItem($item->fresh('run'));

        $callbackWarning = $billing['warning'] ?? null;

        try {
            $this->callbackService->notifySuccess(
                $run->fresh() ?: $run,
                $item->fresh('run') ?: $item,
                is_array($analysis['researchData'] ?? null) ? $analysis['researchData'] : null,
                is_array($analysis['modelUsed'] ?? null) ? $analysis['modelUsed'] : null,
                is_array($billing['cost'] ?? null) ? $billing['cost'] : null,
                is_string($callbackWarning) && $callbackWarning !== '' ? $callbackWarning : null,
            );
        } catch (RuntimeException $exception) {
            $callbackWarning = trim(implode(' | ', array_filter([
                is_string($callbackWarning) ? $callbackWarning : null,
                $exception->getMessage(),
            ])));
        }

        if (is_string($callbackWarning) && $callbackWarning !== '') {
            $freshRun = $run->fresh();
            $responses = is_array($freshRun?->ai_step_responses) ? $freshRun->ai_step_responses : [];
            $responses['deep_research_warning_'.$this->itemStepSuffix($item)] = [
                'step' => 'deep_research_warning_'.$this->itemStepSuffix($item),
                'stepLabel' => 'Deep Research warning',
                'status' => 'warning',
                'provider' => null,
                'model' => null,
                'rawTextPreview' => $callbackWarning,
                'createdAt' => now()->toIso8601String(),
            ];

            if ($freshRun) {
                $freshRun->forceFill(['ai_step_responses' => $responses])->save();
            }
        }

        $run = $item->run()->firstOrFail();
        $this->refreshRunProgress($run);
        $this->dispatchDeepResearchItems($run->fresh('items'));
    }

    public function dispatchNextItems(AuditRun $run): void
    {
        if ($this->isRunCancelled($run)) {
            return;
        }

        $maxParallel = $this->auditSettingsService->maxParallelItems();
        $activeCount = $run->items()
            ->whereIn('status', ['fetching', 'analyzing'])
            ->count();

        if ($activeCount >= $maxParallel) {
            return;
        }

        $slots = $maxParallel - $activeCount;

        $nextItems = $run->items()
            ->where('status', 'queued')
            ->orderBy('position')
            ->limit($slots)
            ->get();

        foreach ($nextItems as $nextItem) {
            ProcessAuditRunItemJob::dispatch($nextItem->id);
        }
    }

    public function markItemFailed(AuditRunItem $item, string $message, bool $stopEntireRun = false): void
    {
        $item->forceFill([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();

        $this->syncItemIfEnabled($item->fresh('run'));
        $this->urlResultService->upsertFromItem($item->fresh('run'));

        if ($stopEntireRun) {
            $this->stopRun($item->run()->firstOrFail(), $message);

            return;
        }

        $run = $item->run()->firstOrFail();
        $this->refreshRunProgress($run);
        if (($run->workflow ?? AuditRun::WORKFLOW_STANDARD) === AuditRun::WORKFLOW_AUDIT_DEEP_RESEARCH) {
            $this->dispatchDeepResearchBatches($run->fresh('items'));

            return;
        }

        $this->dispatchNextItems($run->fresh('items'));
    }

    public function refreshRunProgress(AuditRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $run = AuditRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($run->cancelled_at !== null || in_array($run->status, ['completed', 'failed', 'partial'], true)) {
                return;
            }

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

        $this->syncRunIfEnabled($run->fresh());
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
            'workflow' => $run->workflow ?: AuditRun::WORKFLOW_STANDARD,
            'targetUrls' => [],
            'categories' => [],
            'categoryContexts' => [],
            'checklistText' => null,
            'aiProvider' => $run->ai_provider ?? 'openai',
            'aiModel' => $run->ai_model,
            'step2AiProvider' => $this->stepAiProvider($run, 2),
            'step2AiModel' => $this->stepAiModel($run, 2),
            'step3AiProvider' => $this->stepAiProvider($run, 3),
            'step3AiModel' => $this->stepAiModel($run, 3),
            'step2FormatterProvider' => $run->step2_formatter_provider,
            'step2FormatterModel' => $run->step2_formatter_model,
            'step3FormatterProvider' => $run->step3_formatter_provider,
            'step3FormatterModel' => $run->step3_formatter_model,
            'deepResearchResearchModel' => $run->deep_research_research_model,
            'deepResearchReasoningModel' => $run->deep_research_reasoning_model,
            'deepResearchFormatterProvider' => $run->deep_research_formatter_provider,
            'deepResearchFormatterModel' => $run->deep_research_formatter_model,
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
            'extractionSource' => $item->extraction_source,
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
            'workflow' => $run->workflow ?: AuditRun::WORKFLOW_STANDARD,
            'callbackUrl' => $run->callback_url,
            'targetUrls' => $run->target_urls ?? [],
            'categories' => $run->categories ?? [],
            'categoryContexts' => $run->category_contexts ?? [],
            'checklistText' => $run->checklist_text,
            'aiProvider' => $run->ai_provider ?? 'openai',
            'aiModel' => $run->ai_model,
            'step2AiProvider' => $this->stepAiProvider($run, 2),
            'step2AiModel' => $this->stepAiModel($run, 2),
            'step3AiProvider' => $this->stepAiProvider($run, 3),
            'step3AiModel' => $this->stepAiModel($run, 3),
            'step2FormatterProvider' => $run->step2_formatter_provider,
            'step2FormatterModel' => $run->step2_formatter_model,
            'step3FormatterProvider' => $run->step3_formatter_provider,
            'step3FormatterModel' => $run->step3_formatter_model,
            'deepResearchResearchModel' => $run->deep_research_research_model,
            'deepResearchReasoningModel' => $run->deep_research_reasoning_model,
            'deepResearchFormatterProvider' => $run->deep_research_formatter_provider,
            'deepResearchFormatterModel' => $run->deep_research_formatter_model,
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
            'aiStepResponses' => $this->compactAiStepResponses($run->ai_step_responses ?? []),
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

    /**
     * @param  mixed  $responses
     * @return array<string, mixed>
     */
    private function compactAiStepResponses(mixed $responses): array
    {
        if (! is_array($responses)) {
            return [];
        }

        $compact = [];

        foreach ($responses as $step => $record) {
            if (! is_array($record)) {
                continue;
            }

            $compact[(string) $step] = [
                'step' => $record['step'] ?? (string) $step,
                'stepLabel' => $record['stepLabel'] ?? null,
                'status' => $record['status'] ?? null,
                'provider' => $record['provider'] ?? null,
                'model' => $record['model'] ?? null,
                'interactionId' => $record['interactionId'] ?? null,
                'parseError' => $record['parseError'] ?? null,
                'requestPath' => $record['requestPath'] ?? null,
                'requestBytes' => $record['requestBytes'] ?? null,
                'requestOriginalBytes' => $record['requestOriginalBytes'] ?? null,
                'requestTruncated' => (bool) ($record['requestTruncated'] ?? false),
                'requestPreview' => $record['requestPreview'] ?? null,
                'requestCreatedAt' => $record['requestCreatedAt'] ?? null,
                'rawTextPath' => $record['rawTextPath'] ?? null,
                'rawTextBytes' => $record['rawTextBytes'] ?? null,
                'rawTextOriginalBytes' => $record['rawTextOriginalBytes'] ?? null,
                'rawTextTruncated' => (bool) ($record['rawTextTruncated'] ?? false),
                'rawTextPreview' => $record['rawTextPreview'] ?? null,
                'createdAt' => $record['createdAt'] ?? null,
            ];
        }

        return $compact;
    }
}
