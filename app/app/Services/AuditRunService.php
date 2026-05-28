<?php

namespace App\Services;

use App\Jobs\ProcessAuditRunJob;
use App\Jobs\ProcessAuditDeepResearchBatchJob;
use App\Jobs\ProcessAuditRunItemJob;
use App\Jobs\ProcessAuditRunStep1BatchJob;
use App\Jobs\ProcessAuditRunStep2BatchJob;
use App\Jobs\ProcessAuditRunStep3BatchJob;
use App\Models\AuditRun;
use App\Models\AuditRunItem;
use App\Models\WebsiteAuditUrlResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AuditRunService
{
    public const START_FROM_STEP_1 = 1;
    public const START_FROM_STEP_2 = 2;
    public const START_FROM_STEP_3 = 3;
    public const STOP_AFTER_STEP_1 = 1;
    public const STOP_AFTER_STEP_2 = 2;

    private const SOURCE_STEP1_RUNNING = 'url_only_batch_step1_running';
    private const SOURCE_STEP1_DONE = 'url_only_batch_step1_done';
    private const SOURCE_STEP1_ONLY_COMPLETED = 'url_only_batch_step1_only_completed';
    private const SOURCE_STEP2_RUNNING = 'url_only_batch_step2_running';
    private const SOURCE_STEP2_DONE = 'url_only_batch_step2_done';
    private const SOURCE_STEP2_ONLY_COMPLETED = 'url_only_batch_step2_only_completed';
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
        $requestedTargetUrls = array_values(array_unique($payload['targetUrls']));
        $startFromStep = $this->normalizeStartFromStep($payload['startFromStep'] ?? null);
        $stopAfterStep = $this->normalizeStopAfterStep($payload['stopAfterStep'] ?? null, $startFromStep);
        $queuedTargetUrls = $requestedTargetUrls;
        $step3SeedResults = collect();
        $step1SeedResults = collect();

        if ($startFromStep === self::START_FROM_STEP_3) {
            $step3SeedResults = $this->loadStep3SeedResults((string) $payload['websiteId'], $requestedTargetUrls);
            $queuedTargetUrls = $step3SeedResults->pluck('target_url')->values()->all();

            if ($queuedTargetUrls === []) {
                throw new RuntimeException('Không có URL nào đủ dữ liệu bước 2 để chạy từ bước 3. Hãy chạy lại từ bước 2 hoặc chọn các URL đã có keyword + danh mục.');
            }
        } elseif ($startFromStep === self::START_FROM_STEP_2) {
            $step1SeedResults = $this->loadStep1SeedResults((string) $payload['websiteId'], $requestedTargetUrls);
        }

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
        $run = DB::transaction(function () use ($userUid, $userEmail, $payload, $website, $workflow, $settings, $queuedTargetUrls, $step1SeedResults, $step3SeedResults, $startFromStep, $stopAfterStep): AuditRun {
            $targetUrls = $queuedTargetUrls;
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
                'start_from_step' => $startFromStep,
                'stop_after_step' => $stopAfterStep,
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
                'deep_research_research_provider' => $settings['deepResearchResearchProvider'],
                'deep_research_research_model' => $settings['deepResearchResearchModel'],
                'deep_research_reasoning_provider' => $settings['deepResearchReasoningProvider'],
                'deep_research_reasoning_model' => $settings['deepResearchReasoningModel'],
                'deep_research_formatter_provider' => $settings['deepResearchFormatterProvider'],
                'deep_research_formatter_model' => $settings['deepResearchFormatterModel'],
                'total_urls' => count($targetUrls),
                'processed_urls' => 0,
                'completed_urls' => 0,
                'failed_urls' => 0,
            ]);

            foreach ($targetUrls as $index => $url) {
                $step1Seed = $step1SeedResults->firstWhere('target_url', $url);
                $step3Seed = $step3SeedResults->firstWhere('target_url', $url);
                $seedResult = $step3Seed ?: $step1Seed;
                $initialSource = match ($startFromStep) {
                    self::START_FROM_STEP_3 => self::SOURCE_STEP2_DONE,
                    self::START_FROM_STEP_2 => ($step1Seed ? self::SOURCE_STEP1_DONE : null),
                    default => null,
                };

                $run->items()->create([
                    'public_id' => (string) Str::ulid(),
                    'position' => $index + 1,
                    'target_url' => $url,
                    'status' => $startFromStep === self::START_FROM_STEP_3 ? 'analyzing' : 'queued',
                    'extraction_source' => $initialSource,
                    'content_source' => $seedResult?->content_source,
                    'content_error' => $seedResult?->content_error,
                    'page_title' => $seedResult?->page_title,
                    'meta_description' => $seedResult?->meta_description,
                    'canonical_url' => $seedResult?->canonical_url,
                    'extracted_headings' => $seedResult?->extracted_headings,
                    'extracted_metrics' => $seedResult?->extracted_metrics,
                    'content_excerpt' => $seedResult?->content_excerpt,
                    'primary_keyword' => $seedResult?->primary_keyword,
                    'category_name' => $seedResult?->category_name,
                    'category_url' => $seedResult?->category_url,
                    'category_match_reason' => $seedResult?->category_match_reason,
                ]);
            }

            return $run->fresh('items');
        });

        ProcessAuditRunJob::dispatch($run->id);

        return $run;
    }

    public function requestedTargetUrlsForRun(array $payload): array
    {
        return array_values(array_unique(array_filter(
            (array) ($payload['targetUrls'] ?? []),
            fn (mixed $url): bool => is_string($url) && trim($url) !== '',
        )));
    }

    public function normalizeStartFromStep(mixed $value): int
    {
        $step = (int) $value;

        return in_array($step, [self::START_FROM_STEP_1, self::START_FROM_STEP_2, self::START_FROM_STEP_3], true)
            ? $step
            : self::START_FROM_STEP_1;
    }

    public function normalizeStopAfterStep(mixed $value, int $startFromStep = self::START_FROM_STEP_1): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $step = (int) $value;

        if (! in_array($step, [self::STOP_AFTER_STEP_1, self::STOP_AFTER_STEP_2, self::START_FROM_STEP_3], true)) {
            return null;
        }

        return $step >= $startFromStep ? $step : null;
    }

    /**
     * @param  array<int, string>  $targetUrls
     * @return \Illuminate\Support\Collection<int, WebsiteAuditUrlResult>
     */
    public function loadStep3SeedResults(string $websiteId, array $targetUrls): \Illuminate\Support\Collection
    {
        if ($targetUrls === []) {
            return collect();
        }

        $resultsByUrl = WebsiteAuditUrlResult::query()
            ->where('website_id', $websiteId)
            ->whereIn('target_url', $targetUrls)
            ->get()
            ->filter(fn (WebsiteAuditUrlResult $result): bool => $this->hasStep2SeedData($result))
            ->keyBy('target_url');

        return collect($targetUrls)
            ->map(fn (string $url): ?WebsiteAuditUrlResult => $resultsByUrl->get($url))
            ->filter(fn (?WebsiteAuditUrlResult $result): bool => $result instanceof WebsiteAuditUrlResult)
            ->values();
    }

    /**
     * @param  array<int, string>  $targetUrls
     * @return \Illuminate\Support\Collection<int, WebsiteAuditUrlResult>
     */
    public function loadStep1SeedResults(string $websiteId, array $targetUrls): \Illuminate\Support\Collection
    {
        if ($targetUrls === []) {
            return collect();
        }

        $resultsByUrl = WebsiteAuditUrlResult::query()
            ->where('website_id', $websiteId)
            ->whereIn('target_url', $targetUrls)
            ->get()
            ->filter(fn (WebsiteAuditUrlResult $result): bool => $this->hasStep1SeedData($result))
            ->keyBy('target_url');

        return collect($targetUrls)
            ->map(fn (string $url): ?WebsiteAuditUrlResult => $resultsByUrl->get($url))
            ->filter(fn (?WebsiteAuditUrlResult $result): bool => $result instanceof WebsiteAuditUrlResult)
            ->values();
    }

    public function hasStep2SeedData(WebsiteAuditUrlResult $result): bool
    {
        return $this->filledText($result->primary_keyword)
            && $this->filledText($result->category_name)
            && $this->filledText($result->category_url);
    }

    public function hasStep1SeedData(WebsiteAuditUrlResult $result): bool
    {
        return $this->filledText($result->page_title)
            || $this->filledText($result->meta_description)
            || $this->filledText($result->content_excerpt)
            || $this->filledText($result->content_source)
            || $this->filledText($result->content_error);
    }

    private function filledText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function step2BatchPagePayload(AuditRunItem $item): array
    {
        $hasStep1Data = $this->filledText($item->page_title)
            || $this->filledText($item->meta_description)
            || $this->filledText($item->content_excerpt)
            || $this->filledText($item->content_source)
            || $this->filledText($item->content_error);

        $payload = [
            'targetUrl' => $item->target_url,
        ];

        if (! $hasStep1Data) {
            return $payload;
        }

        $payload['page'] = [
            'url' => $item->target_url,
            'title' => $item->page_title,
            'metaDescription' => $item->meta_description,
            'canonicalUrl' => $item->canonical_url,
            'headings' => $item->extracted_headings ?? [],
            'metrics' => $item->extracted_metrics ?? [],
            'contentExcerpt' => $item->content_excerpt ? mb_substr($item->content_excerpt, 0, 3000) : null,
            'source' => $item->content_source,
            'extractionError' => $item->content_error,
        ];
        $payload['articleContent'] = $item->content_excerpt ? mb_substr($item->content_excerpt, 0, 3000) : null;

        return $payload;
    }

    private function readerUrlFor(string $targetUrl): string
    {
        return rtrim((string) config('services.audit.jina_base_url', 'https://r.jina.ai/'), '/').'/'.$targetUrl;
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

        if ((int) ($run->start_from_step ?? self::START_FROM_STEP_1) === self::START_FROM_STEP_1) {
            $this->dispatchStep1Batches($run);

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

        if ((int) ($run->start_from_step ?? self::START_FROM_STEP_1) === self::START_FROM_STEP_1) {
            $this->dispatchStep1Batches($run);

            return;
        }

        $this->dispatchStep2Batches($run);
    }

    public function dispatchStep1Batches(AuditRun $run): void
    {
        $state = DB::transaction(function () use ($run): array {
            $freshRun = AuditRun::query()->lockForUpdate()->find($run->id);

            if (! $freshRun || $this->isRunCancelled($freshRun)) {
                return ['itemIds' => [], 'step1Complete' => false];
            }

            $settings = $this->auditSettingsService->getAuditSettings();
            $maxParallel = max(1, (int) ($settings['maxParallelItems'] ?? 3));
            $activeItems = $freshRun->items()
                ->where('status', 'fetching')
                ->where('extraction_source', self::SOURCE_STEP1_RUNNING)
                ->count();
            $slots = max(0, $maxParallel - $activeItems);

            if ($slots === 0) {
                return ['itemIds' => [], 'step1Complete' => false];
            }

            $pendingItems = $freshRun->items()
                ->where('status', 'queued')
                ->whereNull('extraction_source')
                ->orderBy('position')
                ->limit($slots)
                ->get(['id']);

            $itemIds = $pendingItems
                ->pluck('id')
                ->values()
                ->all();

            if ($itemIds !== []) {
                $freshRun->items()
                    ->whereIn('id', $itemIds)
                    ->update([
                        'status' => 'fetching',
                        'extraction_source' => self::SOURCE_STEP1_RUNNING,
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
            }

            $hasQueued = $freshRun->items()
                ->where('status', 'queued')
                ->whereNull('extraction_source')
                ->exists();
            $hasRunning = $freshRun->items()
                ->where('status', 'fetching')
                ->where('extraction_source', self::SOURCE_STEP1_RUNNING)
                ->exists();

            return [
                'itemIds' => $itemIds,
                'step1Complete' => ! $hasQueued && ! $hasRunning,
            ];
        });

        if (count($state['itemIds']) > 0) {
            $this->syncRunIfEnabled($run->fresh());
        }

        foreach ($state['itemIds'] as $itemId) {
            ProcessAuditRunStep1BatchJob::dispatch($run->id, [$itemId]);
        }

        if ($state['step1Complete'] && $this->shouldStopAfterStep($run, self::STOP_AFTER_STEP_1)) {
            $this->finalizeStep1OnlyRun($run);

            return;
        }

        if ($state['step1Complete']) {
            $this->dispatchStep2Batches($run);
        }
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

        if ($state['step2Complete'] && $this->shouldStopAfterStep($run, self::STOP_AFTER_STEP_2)) {
            $this->finalizeStep2OnlyRun($run);

            return;
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
    public function processStep1Batch(AuditRun $run, array $itemIds): void
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

        foreach ($items as $item) {
            if ($this->isRunCancelled($run->fresh())) {
                return;
            }

            $page = $this->contentExtractionService->extractOrFallback($item->target_url);

            $item->forceFill([
                'status' => 'queued',
                'extraction_source' => self::SOURCE_STEP1_DONE,
                'content_source' => $page['source'] ?? null,
                'content_error' => $page['extractionError'] ?? null,
                'page_title' => $page['title'] ?? null,
                'meta_description' => $page['metaDescription'] ?? null,
                'canonical_url' => $page['canonicalUrl'] ?? null,
                'extracted_headings' => $page['headings'] ?? [],
                'extracted_metrics' => $page['metrics'] ?? [],
                'content_excerpt' => $page['content'] ?? null,
                'error_message' => null,
                'completed_at' => null,
            ])->save();

            $this->syncItemIfEnabled($item->fresh('run'));
            $this->urlResultService->upsertFromItem($item->fresh('run'));
        }

        $this->dispatchStep1Batches($run);
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

        $batchPages = $items->map(fn (AuditRunItem $item): array => $this->step2BatchPagePayload($item))
            ->values()
            ->all();

        $analysis = $this->seoAiAuditService->analyzeBatchKeywordCategoryUrlOnly(
            targetUrls: $items->pluck('target_url')->values()->all(),
            categories: $run->categories ?? [],
            provider: $this->stepAiProvider($run, 2),
            model: $this->stepAiModel($run, 2),
            formatterProvider: $run->step2_formatter_provider,
            formatterModel: $run->step2_formatter_model,
            auditRunId: $run->id,
            persistStep: $this->chunkStepKey('batch_keyword_category_mapping', $items),
            batchPages: $batchPages,
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
            $this->urlResultService->upsertFromItem($item->fresh('run'));
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

        $stillOwnedByWorker = $run->items()
            ->whereIn('id', $itemIds)
            ->where('status', 'analyzing')
            ->where('extraction_source', self::SOURCE_STEP3_RUNNING)
            ->count() === count($itemIds);

        if (! $stillOwnedByWorker) {
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

        $this->applyStep3BatchAnalysis($run, $items, $analysis);
    }

    public function watchdogActiveRun(AuditRun $run): void
    {
        $freshRun = $run->fresh('items');

        if (! $freshRun || $freshRun->cancelled_at !== null || ! in_array($freshRun->status, ['queued', 'processing'], true)) {
            return;
        }

        $this->recoverStaleGeminiDeepResearchStep3Batches($freshRun);
    }

    /**
     * @return array{
     *     scanned: int,
     *     changed: int,
     *     recovered: int,
     *     failedMarked: int,
     *     unchanged: int,
     *     runs: array<int, array{
     *         publicId: string,
     *         statusBefore: string|null,
     *         statusAfter: string|null,
     *         processedBefore: int,
     *         processedAfter: int,
     *         completedBefore: int,
     *         completedAfter: int,
     *         failedBefore: int,
     *         failedAfter: int,
     *         changed: bool,
     *         recovered: bool,
     *         failedMarked: bool
     *     }>
     * }
     */
    public function recoverStaleRuns(?int $limit = null): array
    {
        $effectiveLimit = $limit ?? (int) config('services.audit.stale_run_recovery_limit', 20);
        $effectiveLimit = max(1, min(200, $effectiveLimit));

        $runs = AuditRun::query()
            ->whereNull('cancelled_at')
            ->whereIn('status', ['queued', 'processing'])
            ->where('step3_ai_provider', 'gemini_deep_research')
            ->whereHas('items', function ($query): void {
                $query
                    ->where('status', 'analyzing')
                    ->where('extraction_source', self::SOURCE_STEP3_RUNNING);
            })
            ->with(['items' => fn ($query) => $query->orderBy('position')])
            ->orderBy('updated_at')
            ->limit($effectiveLimit)
            ->get();

        $summary = [
            'scanned' => 0,
            'changed' => 0,
            'recovered' => 0,
            'failedMarked' => 0,
            'unchanged' => 0,
            'runs' => [],
        ];

        foreach ($runs as $run) {
            $before = [
                'status' => $run->status,
                'processed' => (int) $run->processed_urls,
                'completed' => (int) $run->completed_urls,
                'failed' => (int) $run->failed_urls,
            ];

            $this->watchdogActiveRun($run);

            $afterRun = $run->fresh(['items' => fn ($query) => $query->orderBy('position')]);
            $after = [
                'status' => $afterRun?->status,
                'processed' => (int) ($afterRun?->processed_urls ?? 0),
                'completed' => (int) ($afterRun?->completed_urls ?? 0),
                'failed' => (int) ($afterRun?->failed_urls ?? 0),
            ];

            $changed = $before !== $after;
            $recovered = $after['completed'] > $before['completed'];
            $failedMarked = $after['failed'] > $before['failed'];

            $summary['scanned']++;
            $summary[$changed ? 'changed' : 'unchanged']++;
            if ($recovered) {
                $summary['recovered']++;
            }
            if ($failedMarked) {
                $summary['failedMarked']++;
            }

            $summary['runs'][] = [
                'publicId' => (string) $run->public_id,
                'statusBefore' => $before['status'],
                'statusAfter' => $after['status'],
                'processedBefore' => $before['processed'],
                'processedAfter' => $after['processed'],
                'completedBefore' => $before['completed'],
                'completedAfter' => $after['completed'],
                'failedBefore' => $before['failed'],
                'failedAfter' => $after['failed'],
                'changed' => $changed,
                'recovered' => $recovered,
                'failedMarked' => $failedMarked,
            ];
        }

        return $summary;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AuditRunItem>  $items
     * @param  array{items: array<int, array<string, mixed>>, promptSnapshot?: array<string, mixed>|null, formatterPromptSnapshot?: array<string, mixed>|null}  $analysis
     */
    private function applyStep3BatchAnalysis(AuditRun $run, \Illuminate\Support\Collection $items, array $analysis): void
    {
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

    private function recoverStaleGeminiDeepResearchStep3Batches(AuditRun $run): void
    {
        if ($this->stepAiProvider($run, 3) !== 'gemini_deep_research') {
            return;
        }

        $settings = $this->auditSettingsService->getAuditSettings();
        $batchSize = max(1, (int) ($settings['step3BatchSize'] ?? 30));
        $activeItems = $run->items()
            ->where('status', 'analyzing')
            ->where('extraction_source', self::SOURCE_STEP3_RUNNING)
            ->orderBy('position')
            ->get();

        if ($activeItems->isEmpty()) {
            return;
        }

        $responses = is_array($run->ai_step_responses) ? $run->ai_step_responses : [];

        foreach ($activeItems->chunk($batchSize) as $chunk) {
            $stepKey = $this->chunkStepKey('batch_onpage_audit', $chunk);
            $record = is_array($responses[$stepKey] ?? null) ? $responses[$stepKey] : [];

            if (! $this->isStep3ChunkStale($chunk, $record)) {
                continue;
            }

            $this->mergeRunAiStepResponse($run, $stepKey, [
                'step' => $stepKey,
                'stepLabel' => 'Bước 3 watchdog',
                'status' => 'watchdog_stale_detected',
                'provider' => $record['provider'] ?? 'gemini_deep_research',
                'model' => $record['model'] ?? $this->stepAiModel($run, 3),
                'interactionId' => $record['interactionId'] ?? null,
                'staleDetectedAt' => now()->toIso8601String(),
                'createdAt' => now()->toIso8601String(),
            ]);

            $interactionId = trim((string) ($record['interactionId'] ?? ''));

            if ($interactionId === '') {
                $this->markBatchItemIdsFailed(
                    $run,
                    $chunk->pluck('id')->values()->all(),
                    'Bước 3 bị kẹt quá lâu và không có interaction id của Gemini Deep Research. Worker có thể đã chết trước khi lưu trạng thái. Hãy chạy lại batch từ bước 3.'
                );
                $this->dispatchStep3Batches($run);

                continue;
            }

            try {
                $inspection = $this->seoAiAuditService->inspectGeminiDeepResearchInteraction(
                    interactionId: $interactionId,
                    auditRunId: $run->id,
                    persistStep: $stepKey,
                    model: $this->stepAiModel($run, 3),
                );
                $remoteStatus = Str::lower((string) ($inspection['status'] ?? 'unknown'));
            } catch (\Throwable $exception) {
                $this->markBatchItemIdsFailed(
                    $run,
                    $chunk->pluck('id')->values()->all(),
                    'Không thể kiểm tra lại trạng thái Gemini Deep Research bị kẹt: '.$exception->getMessage()
                );
                $this->dispatchStep3Batches($run);

                continue;
            }

            if ($remoteStatus === 'completed' && is_string($inspection['rawText'] ?? null) && trim((string) $inspection['rawText']) !== '') {
                $keywordCategoryItems = $chunk->map(fn (AuditRunItem $item): array => [
                    'targetUrl' => $item->target_url,
                    'primaryKeyword' => $item->primary_keyword,
                    'categoryName' => $item->category_name,
                    'categoryUrl' => $item->category_url,
                    'categoryMatchReason' => $item->category_match_reason,
                ])->values()->all();

                try {
                    $analysis = $this->seoAiAuditService->resumeBatchOnpageUrlOnlyFromRaw(
                        targetUrls: $chunk->pluck('target_url')->values()->all(),
                        categories: $run->categories ?? [],
                        checklistText: $run->checklist_text,
                        keywordCategoryItems: $keywordCategoryItems,
                        provider: 'gemini_deep_research',
                        model: $this->stepAiModel($run, 3),
                        rawText: (string) $inspection['rawText'],
                        usage: is_array($inspection['usage'] ?? null) ? $inspection['usage'] : [],
                        formatterProvider: $run->step3_formatter_provider,
                        formatterModel: $run->step3_formatter_model,
                        auditRunId: $run->id,
                        persistStep: $stepKey,
                        interactionId: $interactionId,
                    );
                } catch (\Throwable $exception) {
                    $this->markBatchItemIdsFailed(
                        $run,
                        $chunk->pluck('id')->values()->all(),
                        'Gemini Deep Research đã hoàn tất từ xa nhưng không dựng lại được kết quả batch: '.$exception->getMessage()
                    );
                    $this->dispatchStep3Batches($run);

                    continue;
                }

                $firstItem = $chunk->first();

                if ($firstItem) {
                    foreach ($analysis['usageEvents'] ?? [] as $usage) {
                        if (is_array($usage)) {
                            $this->tokenBillingService->chargeForAiCall($firstItem->fresh('run'), (string) ($usage['step'] ?? 'batch_onpage_audit'), $usage);
                        }
                    }
                }

                $this->applyStep3BatchAnalysis($run, $chunk, $analysis);

                continue;
            }

            if (in_array($remoteStatus, ['failed', 'cancelled'], true)) {
                $message = 'Gemini Deep Research '.($inspection['status'] ?? 'failed').': '.((string) ($inspection['errorMessage'] ?? 'Unknown error.'));
                $this->markBatchItemIdsFailed($run, $chunk->pluck('id')->values()->all(), $message);
                $this->dispatchStep3Batches($run);

                continue;
            }

            $run->items()
                ->whereIn('id', $chunk->pluck('id')->values()->all())
                ->update(['updated_at' => now()]);
        }
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
    public function recoverStep1BatchItemIdsWithoutContent(AuditRun $run, array $itemIds, string $message): void
    {
        if ($itemIds === []) {
            return;
        }

        $run->items()
            ->whereIn('id', $itemIds)
            ->where('status', 'fetching')
            ->where('extraction_source', self::SOURCE_STEP1_RUNNING)
            ->update([
                'status' => 'queued',
                'extraction_source' => self::SOURCE_STEP1_DONE,
                'content_source' => DB::raw("COALESCE(content_source, 'url_only')"),
                'content_error' => $message,
                'error_message' => null,
                'completed_at' => null,
                'updated_at' => now(),
            ]);

        foreach ($run->items()->whereIn('id', $itemIds)->get() as $item) {
            $this->syncItemIfEnabled($item->fresh('run'));
            $this->urlResultService->upsertFromItem($item->fresh('run'));
        }
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
        $totalProviderReportedUsd = 0.0;
        $hasProviderReportedUsd = false;

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
                    'citationTokens' => (int) ($usage['citation_tokens'] ?? 0),
                    'reasoningTokens' => (int) ($usage['reasoning_tokens'] ?? 0),
                    'searchQueries' => (int) ($usage['search_queries'] ?? 0),
                    'providerReportedCostUsd' => is_numeric($usage['provider_reported_cost_usd'] ?? null)
                        ? round((float) $usage['provider_reported_cost_usd'], 6)
                        : null,
                    'creditsCharged' => (int) $event->credits_charged,
                ];
                $totalCredits += (int) $event->credits_charged;

                if (is_numeric($usage['provider_reported_cost_usd'] ?? null)) {
                    $totalProviderReportedUsd += (float) $usage['provider_reported_cost_usd'];
                    $hasProviderReportedUsd = true;
                }
            } catch (RuntimeException $exception) {
                $warnings[] = $exception->getMessage();
                report($exception);
            }
        }

        return [
            'warning' => $warnings !== [] ? implode(' | ', array_unique($warnings)) : null,
            'cost' => [
                'totalCreditsCharged' => $totalCredits,
                'totalProviderReportedCostUsd' => $hasProviderReportedUsd ? round($totalProviderReportedUsd, 6) : null,
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

    /**
     * @param  array<string, mixed>  $record
     */
    private function mergeRunAiStepResponse(AuditRun $run, string $step, array $record): void
    {
        $freshRun = $run->fresh();

        if (! $freshRun) {
            return;
        }

        $responses = is_array($freshRun->ai_step_responses) ? $freshRun->ai_step_responses : [];
        $existing = is_array($responses[$step] ?? null) ? $responses[$step] : [];
        $responses[$step] = array_merge($existing, $record);
        $freshRun->forceFill(['ai_step_responses' => $responses])->save();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AuditRunItem>  $chunk
     * @param  array<string, mixed>  $record
     */
    private function isStep3ChunkStale(\Illuminate\Support\Collection $chunk, array $record): bool
    {
        $heartbeatUnix = $this->step3HeartbeatUnix($record);
        $staleSeconds = $this->step3WatchdogStaleSeconds();

        if ($heartbeatUnix !== null) {
            return (time() - $heartbeatUnix) >= $staleSeconds;
        }

        $lastUpdatedUnix = $chunk
            ->map(fn (AuditRunItem $item): ?int => $item->updated_at?->getTimestamp())
            ->filter(fn (?int $timestamp): bool => $timestamp !== null)
            ->max();

        if (! is_int($lastUpdatedUnix)) {
            return false;
        }

        return (time() - $lastUpdatedUnix) >= $staleSeconds;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function step3HeartbeatUnix(array $record): ?int
    {
        foreach (['lastPollAt', 'interactionStartedAt', 'createdAt', 'requestCreatedAt'] as $field) {
            $value = $record[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $timestamp = strtotime($value);

            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    private function step3WatchdogStaleSeconds(): int
    {
        return max(30, (int) config('services.audit.gemini_deep_research_watchdog_stale_seconds', 120));
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
            researchProvider: $run->deep_research_research_provider,
            researchModel: $run->deep_research_research_model,
            reasoningProvider: $run->deep_research_reasoning_provider,
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
                researchProvider: $run->deep_research_research_provider,
                researchModel: $run->deep_research_research_model,
                reasoningProvider: $run->deep_research_reasoning_provider,
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

    private function shouldStopAfterStep(AuditRun $run, int $step): bool
    {
        return (int) ($run->stop_after_step ?? 0) === $step;
    }

    private function finalizeStep1OnlyRun(AuditRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $freshRun = AuditRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($freshRun->cancelled_at !== null || in_array($freshRun->status, ['completed', 'failed', 'partial'], true)) {
                return;
            }

            $freshRun->items()
                ->where('status', 'queued')
                ->where('extraction_source', self::SOURCE_STEP1_DONE)
                ->update([
                    'status' => 'completed',
                    'extraction_source' => self::SOURCE_STEP1_ONLY_COMPLETED,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        $freshRun = $run->fresh('items');

        foreach ($freshRun?->items ?? [] as $item) {
            if ($item->extraction_source === self::SOURCE_STEP1_ONLY_COMPLETED) {
                $this->syncItemIfEnabled($item);
                $this->urlResultService->upsertFromItem($item->fresh('run'));
            }
        }

        $this->refreshRunProgress($run);
    }

    private function finalizeStep2OnlyRun(AuditRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $freshRun = AuditRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($freshRun->cancelled_at !== null || in_array($freshRun->status, ['completed', 'failed', 'partial'], true)) {
                return;
            }

            $freshRun->items()
                ->where('status', 'analyzing')
                ->where('extraction_source', self::SOURCE_STEP2_DONE)
                ->update([
                    'status' => 'completed',
                    'extraction_source' => self::SOURCE_STEP2_ONLY_COMPLETED,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        $freshRun = $run->fresh('items');

        foreach ($freshRun?->items ?? [] as $item) {
            if ($item->extraction_source === self::SOURCE_STEP2_ONLY_COMPLETED) {
                $this->syncItemIfEnabled($item);
                $this->urlResultService->upsertFromItem($item->fresh('run'));
            }
        }

        $this->refreshRunProgress($run);
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
            'startFromStep' => $run->start_from_step,
            'stopAfterStep' => $run->stop_after_step,
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
            'deepResearchResearchProvider' => $run->deep_research_research_provider,
            'deepResearchResearchModel' => $run->deep_research_research_model,
            'deepResearchReasoningProvider' => $run->deep_research_reasoning_provider,
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
            'contentSource' => $item->content_source,
            'contentError' => $item->content_error,
            'readerUrl' => $this->readerUrlFor($item->target_url),
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
            'auditFindings' => [],
            'auditRecommendations' => array_values(array_filter(is_array($recommendations) ? $recommendations : [])),
            'contentRevisionDirection' => $item->content_revision_direction,
            'contentExcerpt' => $item->content_excerpt ? mb_substr($item->content_excerpt, 0, 1200) : null,
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
            'startFromStep' => $run->start_from_step,
            'stopAfterStep' => $run->stop_after_step,
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
            'deepResearchResearchProvider' => $run->deep_research_research_provider,
            'deepResearchResearchModel' => $run->deep_research_research_model,
            'deepResearchReasoningProvider' => $run->deep_research_reasoning_provider,
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
            'usageSummary' => $this->serializeUsageSummary($run),
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
                'contentSource' => $item->content_source,
                'contentError' => $item->content_error,
                'readerUrl' => $this->readerUrlFor($item->target_url),
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
                'contentExcerpt' => $item->content_excerpt ? mb_substr($item->content_excerpt, 0, 2400) : null,
                'promptSnapshots' => $this->compactPromptSnapshots($item->prompt_snapshots ?? []),
                'errorMessage' => $item->error_message,
                'completedAt' => optional($item->completed_at)?->toIso8601String(),
                'createdAt' => optional($item->created_at)?->toIso8601String(),
                'updatedAt' => optional($item->updated_at)?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUsageSummary(AuditRun $run): array
    {
        $rows = DB::table('ai_usage_events')
            ->join('audit_run_items', 'audit_run_items.id', '=', 'ai_usage_events.audit_run_item_id')
            ->where('audit_run_items.audit_run_id', $run->id)
            ->orderBy('ai_usage_events.id')
            ->get([
                'ai_usage_events.step',
                'ai_usage_events.provider',
                'ai_usage_events.model',
                'ai_usage_events.input_tokens',
                'ai_usage_events.output_tokens',
                'ai_usage_events.total_tokens',
                'ai_usage_events.citation_tokens',
                'ai_usage_events.reasoning_tokens',
                'ai_usage_events.search_queries',
                'ai_usage_events.provider_reported_cost_usd',
                'ai_usage_events.credits_charged',
            ]);

        $totals = [
            'eventCount' => 0,
            'inputTokens' => 0,
            'outputTokens' => 0,
            'totalTokens' => 0,
            'citationTokens' => 0,
            'reasoningTokens' => 0,
            'searchQueries' => 0,
            'creditsCharged' => 0,
            'providerReportedCostUsd' => null,
            'estimatedCostUsd' => null,
        ];
        $reportedCostEventCount = 0;
        $totalReportedCostUsd = 0.0;
        $estimatedCostEventCount = 0;
        $totalEstimatedCostUsd = 0.0;
        $byStep = [];

        foreach ($rows as $row) {
            $step = (string) ($row->step ?? 'unknown_ai_step');
            $meta = $this->usageStepMeta($step);
            $groupKey = $meta['key'];
            $reportedCostUsd = is_numeric($row->provider_reported_cost_usd ?? null)
                ? round((float) $row->provider_reported_cost_usd, 6)
                : null;
            $estimatedUsd = $this->tokenBillingService->estimateUsdForUsage([
                'provider' => (string) ($row->provider ?? ''),
                'model' => (string) ($row->model ?? ''),
                'input_tokens' => (int) ($row->input_tokens ?? 0),
                'output_tokens' => (int) ($row->output_tokens ?? 0),
                'citation_tokens' => (int) ($row->citation_tokens ?? 0),
                'reasoning_tokens' => (int) ($row->reasoning_tokens ?? 0),
                'search_queries' => (int) ($row->search_queries ?? 0),
            ]);

            if (! isset($byStep[$groupKey])) {
                $byStep[$groupKey] = [
                    'key' => $groupKey,
                    'label' => $meta['label'],
                    'order' => $meta['order'],
                    'eventCount' => 0,
                    'providers' => [],
                    'models' => [],
                    'rawSteps' => [],
                    'inputTokens' => 0,
                    'outputTokens' => 0,
                    'totalTokens' => 0,
                    'citationTokens' => 0,
                    'reasoningTokens' => 0,
                    'searchQueries' => 0,
                    'creditsCharged' => 0,
                    'providerReportedCostUsd' => null,
                    'estimatedCostUsd' => null,
                ];
            }

            $byStep[$groupKey]['eventCount']++;
            $byStep[$groupKey]['providers'][(string) ($row->provider ?? '')] = true;
            $byStep[$groupKey]['models'][(string) ($row->model ?? '')] = true;
            $byStep[$groupKey]['rawSteps'][$step] = true;
            $byStep[$groupKey]['inputTokens'] += (int) ($row->input_tokens ?? 0);
            $byStep[$groupKey]['outputTokens'] += (int) ($row->output_tokens ?? 0);
            $byStep[$groupKey]['totalTokens'] += (int) ($row->total_tokens ?? 0);
            $byStep[$groupKey]['citationTokens'] += (int) ($row->citation_tokens ?? 0);
            $byStep[$groupKey]['reasoningTokens'] += (int) ($row->reasoning_tokens ?? 0);
            $byStep[$groupKey]['searchQueries'] += (int) ($row->search_queries ?? 0);
            $byStep[$groupKey]['creditsCharged'] += (int) ($row->credits_charged ?? 0);

            if ($reportedCostUsd !== null) {
                $stepTotalUsd = (float) ($byStep[$groupKey]['providerReportedCostUsd'] ?? 0);
                $byStep[$groupKey]['providerReportedCostUsd'] = round($stepTotalUsd + $reportedCostUsd, 6);
                $reportedCostEventCount++;
                $totalReportedCostUsd += $reportedCostUsd;
            }

            if ($estimatedUsd['amount'] !== null) {
                $stepEstimatedUsd = (float) ($byStep[$groupKey]['estimatedCostUsd'] ?? 0);
                $byStep[$groupKey]['estimatedCostUsd'] = round($stepEstimatedUsd + (float) $estimatedUsd['amount'], 6);
                $estimatedCostEventCount++;
                $totalEstimatedCostUsd += (float) $estimatedUsd['amount'];
            }

            $totals['eventCount']++;
            $totals['inputTokens'] += (int) ($row->input_tokens ?? 0);
            $totals['outputTokens'] += (int) ($row->output_tokens ?? 0);
            $totals['totalTokens'] += (int) ($row->total_tokens ?? 0);
            $totals['citationTokens'] += (int) ($row->citation_tokens ?? 0);
            $totals['reasoningTokens'] += (int) ($row->reasoning_tokens ?? 0);
            $totals['searchQueries'] += (int) ($row->search_queries ?? 0);
            $totals['creditsCharged'] += (int) ($row->credits_charged ?? 0);
        }

        $groups = array_values(array_map(function (array $group): array {
            $group['providers'] = array_values(array_filter(array_keys($group['providers'])));
            $group['models'] = array_values(array_filter(array_keys($group['models'])));
            $group['rawSteps'] = array_values(array_filter(array_keys($group['rawSteps'])));

            return $group;
        }, $byStep));

        usort($groups, function (array $left, array $right): int {
            $byOrder = ((int) ($left['order'] ?? 999)) <=> ((int) ($right['order'] ?? 999));

            return $byOrder !== 0 ? $byOrder : ((string) ($left['key'] ?? '')) <=> ((string) ($right['key'] ?? ''));
        });

        $groups = array_values(array_map(function (array $group): array {
            unset($group['order']);

            return $group;
        }, $groups));

        if ($reportedCostEventCount > 0) {
            $totals['providerReportedCostUsd'] = round($totalReportedCostUsd, 6);
        }

        if ($estimatedCostEventCount > 0) {
            $totals['estimatedCostUsd'] = round($totalEstimatedCostUsd, 6);
        }

        $costVisibility = 'tokens_only';
        $estimateVisibility = 'none';

        if ($totals['eventCount'] > 0 && $reportedCostEventCount > 0) {
            $costVisibility = $reportedCostEventCount === $totals['eventCount'] ? 'reported' : 'partial';
        }

        if ($totals['eventCount'] > 0 && $estimatedCostEventCount > 0) {
            $estimateVisibility = $estimatedCostEventCount === $totals['eventCount'] ? 'estimated' : 'partial';
        }

        return [
            'costVisibility' => $costVisibility,
            'estimateVisibility' => $estimateVisibility,
            'totals' => $totals,
            'byStep' => $groups,
        ];
    }

    /**
     * @return array{key:string,label:string,order:int}
     */
    private function usageStepMeta(string $step): array
    {
        return match (true) {
            str_starts_with($step, 'keyword_category_json_formatter') => ['key' => 'step2_formatter', 'label' => 'Bước 2.5: formatter JSON', 'order' => 25],
            str_starts_with($step, 'batch_keyword_category_mapping') => ['key' => 'step2', 'label' => 'Bước 2: keyword + danh mục', 'order' => 20],
            str_starts_with($step, 'onpage_audit_json_formatter') => ['key' => 'step3_formatter', 'label' => 'Bước 3.5: formatter JSON', 'order' => 35],
            str_starts_with($step, 'batch_onpage_audit') => ['key' => 'step3', 'label' => 'Bước 3: audit onpage', 'order' => 30],
            str_starts_with($step, 'deep_research_research') => ['key' => 'deep_research_3a', 'label' => 'Bước 3A: research', 'order' => 31],
            str_starts_with($step, 'deep_research_audit') => ['key' => 'deep_research_3b', 'label' => 'Bước 3B: reasoning audit', 'order' => 32],
            str_starts_with($step, 'deep_research_json_formatter') => ['key' => 'deep_research_3c', 'label' => 'Bước 3C: JSON formatter', 'order' => 33],
            default => ['key' => 'other', 'label' => 'Bước khác', 'order' => 999],
        };
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
                'remoteStatus' => $record['remoteStatus'] ?? null,
                'interactionStartedAt' => $record['interactionStartedAt'] ?? null,
                'lastPollAt' => $record['lastPollAt'] ?? null,
                'staleDetectedAt' => $record['staleDetectedAt'] ?? null,
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
