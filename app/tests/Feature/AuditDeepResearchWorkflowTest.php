<?php

namespace Tests\Feature;

use App\Jobs\ProcessAuditDeepResearchBatchJob;
use App\Jobs\ProcessAuditRunJob;
use App\Jobs\ProcessAuditRunItemJob;
use App\Jobs\ProcessAuditRunStep3BatchJob;
use App\Models\AiUsageEvent;
use App\Models\AuditRun;
use App\Models\AuditRunItem;
use App\Models\WebsiteAuditUrlResult;
use App\Services\AuditSettingsService;
use App\Services\AuditRunService;
use App\Services\CreditService;
use App\Services\DeepResearchSeoAuditService;
use App\Services\SeoContentExtractionService;
use App\Services\TokenBillingService;
use App\Services\WebsiteDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AuditDeepResearchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_item_failed_for_deep_research_dispatches_deep_research_batch_jobs_only(): void
    {
        Queue::fake();

        $run = $this->makeRun(totalUrls: 2);
        $failedItem = $this->makeItem($run, 1, 'analyzing', 'audit_deep_research_running');
        $pendingItem = $this->makeItem($run, 2, 'analyzing', 'url_only_batch_step2_done');

        app(AuditRunService::class)->markItemFailed($failedItem, 'Perplexity timeout.', false);

        $pendingItem->refresh();

        $this->assertSame('fetching', $pendingItem->status);
        $this->assertSame('audit_deep_research_running', $pendingItem->extraction_source);

        Queue::assertPushed(ProcessAuditDeepResearchBatchJob::class, function (ProcessAuditDeepResearchBatchJob $job) use ($pendingItem): bool {
            return $job->itemIds === [$pendingItem->id];
        });
        Queue::assertNotPushed(ProcessAuditRunItemJob::class);
    }

    public function test_process_deep_research_batch_keeps_completed_result_when_billing_fails(): void
    {
        $run = $this->makeRun();
        $item = $this->makeItem($run, 1, 'fetching', 'audit_deep_research_running');

        $extractor = Mockery::mock(SeoContentExtractionService::class);
        $extractor->shouldReceive('extractOrFallback')->once()->andReturn([
            'url' => $item->target_url,
            'title' => 'Ưu đãi IMS 2026',
            'metaDescription' => 'Ưu đãi du học Philippines tại IMS cập nhật 2026.',
            'canonicalUrl' => $item->target_url,
            'headings' => [
                'h1' => ['Ưu đãi IMS 2026'],
                'h2' => ['Học phí IMS'],
                'h3' => [],
            ],
            'metrics' => [
                'wordCount' => 1200,
                'imageCount' => 2,
            ],
            'content' => 'Nội dung bài viết mẫu.',
            'source' => 'html',
            'extractionError' => null,
        ]);
        $this->app->instance(SeoContentExtractionService::class, $extractor);

        $deepResearch = Mockery::mock(DeepResearchSeoAuditService::class);
        $deepResearch->shouldReceive('analyzeBatch')->once()->withArgs(function (
            array $batchPages,
            array $categories,
            array $categoryContexts,
            array $siteUrls,
            ?string $checklistText,
            ?int $auditRunId,
            ?string $stepSuffix,
            ?string $researchProvider,
            ?string $researchModel,
            ?string $reasoningProvider,
            ?string $reasoningModel,
            ?string $formatterProvider,
            ?string $formatterModel
        ) use ($item, $run): bool {
            return ($batchPages[0]['targetUrl'] ?? null) === $item->target_url
                && ($batchPages[0]['primaryKeywordSeed'] ?? null) === 'ims keyword from step 2'
                && ($batchPages[0]['categoryNameSeed'] ?? null) === 'Trường Anh ngữ IMS'
                && $categories === $run->categories
                && $categoryContexts === $run->category_contexts
                && $siteUrls === $run->target_urls
                && $checklistText === $run->checklist_text
                && $auditRunId === $run->id
                && $researchProvider === 'perplexity'
                && $researchModel === 'sonar-deep-research'
                && $reasoningProvider === 'openai'
                && $reasoningModel === 'gpt-5.5'
                && $formatterProvider === 'openai'
                && $formatterModel === 'gpt-5.5';
        })->andReturn([
            'items' => [
                [
                    'targetUrl' => $item->target_url,
                    'primaryKeyword' => 'ưu đãi IMS 2026',
                    'categoryName' => 'Trường Anh ngữ IMS',
                    'categoryUrl' => 'https://example.com/ims',
                    'categoryMatchReason' => 'Khớp theo nội dung và slug.',
                    'researchData' => [
                        'searchIntent' => 'Transactional',
                        'sources' => [
                            ['title' => 'IMS', 'url' => 'https://example.com/source', 'date' => '2026-01-01', 'snippet' => 'Nguồn mẫu'],
                        ],
                    ],
                    'auditScore' => 82,
                    'auditFindings' => [
                        'Điểm kỹ thuật SEO: 19/24',
                        'Điểm nội dung: 5/6',
                        'STT 7: Keyword đã xuất hiện đúng vị trí chính',
                        'STT 23: Có cập nhật xu hướng năm hiện tại',
                    ],
                    'auditRecommendations' => [
                        'Bổ sung thêm 1 internal link liên quan',
                        'Mở rộng section FAQ cuối bài',
                        'Tăng dữ liệu so sánh học phí với đối thủ',
                        'Thêm CTA nổi bật phía trên fold đầu tiên',
                    ],
                    'contentRevisionDirection' => 'Giữ nguyên. Bài viết đã đúng search intent và có nền tảng SEO tốt. Chỉ cần tối ưu thêm internal link và FAQ để tăng độ phủ. Ưu tiên giữ cấu trúc hiện tại và cập nhật định kỳ dữ liệu mới.',
                ],
            ],
            'promptSnapshots' => [],
            'usageEvents' => [
                [
                    'step' => 'deep_research_audit_001_001',
                    'provider' => 'openai',
                    'model' => 'gpt-5.5',
                    'input_tokens' => 100,
                    'output_tokens' => 50,
                    'total_tokens' => 150,
                ],
            ],
            'modelUsed' => [
                'research' => ['provider' => 'perplexity', 'model' => 'sonar-deep-research'],
                'reasoning' => ['provider' => 'openai', 'model' => 'gpt-5.5'],
                'formatter' => ['provider' => 'openai', 'model' => 'gpt-5.5'],
            ],
        ]);
        $this->app->instance(DeepResearchSeoAuditService::class, $deepResearch);

        $billing = Mockery::mock(TokenBillingService::class);
        $billing->shouldReceive('chargeForAiCall')->once()->andThrow(new RuntimeException('Billing provider unavailable.'));
        $this->app->instance(TokenBillingService::class, $billing);

        app(AuditRunService::class)->processDeepResearchBatch($run, [$item->id]);

        $item->refresh();
        $run->refresh();

        $this->assertSame('completed', $item->status);
        $this->assertSame(82, $item->audit_score);
        $this->assertNull($item->error_message);
        $this->assertSame('completed', $run->status);
    }

    public function test_deep_research_workflow_keeps_step_2_and_dispatches_new_step_3_batches_after_step_2_completes(): void
    {
        Queue::fake();

        app(AuditSettingsService::class)->updateAuditSettings([
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-5.5',
            'step2AiProvider' => 'openai',
            'step2AiModel' => 'gpt-5.5',
            'step3AiProvider' => 'openai',
            'step3AiModel' => 'gpt-5.5',
            'step2FormatterProvider' => 'openai',
            'step2FormatterModel' => 'gpt-5.5',
            'step3FormatterProvider' => 'openai',
            'step3FormatterModel' => 'gpt-5.5',
            'maxParallelItems' => 3,
            'step2BatchSize' => 60,
            'step3BatchSize' => 30,
            'deepResearchBatchSize' => 2,
            'deepResearchResearchProvider' => 'perplexity',
            'deepResearchResearchModel' => 'sonar-deep-research',
            'deepResearchReasoningProvider' => 'openai',
            'deepResearchReasoningModel' => 'gpt-5.5',
            'deepResearchFormatterProvider' => 'openai',
            'deepResearchFormatterModel' => 'gpt-5.5',
        ]);

        $run = $this->makeRun(totalUrls: 2);
        $items = $this->makeItems($run, 2, 'analyzing', 'url_only_batch_step2_done');

        app(AuditRunService::class)->dispatchStep2Batches($run);

        $items->each->refresh();

        foreach ($items as $item) {
            $this->assertSame('fetching', $item->status);
            $this->assertSame('audit_deep_research_running', $item->extraction_source);
        }

        Queue::assertPushed(ProcessAuditDeepResearchBatchJob::class, function (ProcessAuditDeepResearchBatchJob $job) use ($run, $items): bool {
            return $job->runId === $run->id
                && $job->itemIds === $items->pluck('id')->values()->all();
        });
        Queue::assertNotPushed(ProcessAuditRunStep3BatchJob::class);
    }

    public function test_step2_only_run_finalizes_after_step_2_without_dispatching_step_3(): void
    {
        Queue::fake();

        $run = $this->makeRun(2, [
            'workflow' => AuditRun::WORKFLOW_STANDARD,
            'stop_after_step' => 2,
        ]);
        $items = $this->makeItems($run, 2, 'analyzing', 'url_only_batch_step2_done');

        app(AuditRunService::class)->dispatchStep2Batches($run);

        $run->refresh();
        $items->each->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->processed_urls);
        $this->assertSame(2, $run->completed_urls);
        $this->assertSame(0, $run->failed_urls);

        foreach ($items as $item) {
            $this->assertSame('completed', $item->status);
            $this->assertSame('url_only_batch_step2_only_completed', $item->extraction_source);
            $this->assertNotNull($item->completed_at);
        }

        Queue::assertNotPushed(ProcessAuditRunStep3BatchJob::class);
        Queue::assertNotPushed(ProcessAuditDeepResearchBatchJob::class);
    }

    public function test_deep_research_failed_batch_only_fails_its_own_chunk_and_keeps_other_chunks_running(): void
    {
        Queue::fake();

        $run = $this->makeRun(totalUrls: 4);
        $failedChunk = $this->makeItems($run, 2, 'analyzing', 'audit_deep_research_running', 1);
        $pendingChunk = $this->makeItems($run, 2, 'analyzing', 'url_only_batch_step2_done', 3);

        $job = new ProcessAuditDeepResearchBatchJob($run->id, $failedChunk->pluck('id')->all());
        $job->failed(new RuntimeException('Perplexity timeout.'));

        $failedChunk->each->refresh();
        $pendingChunk->each->refresh();
        $run->refresh();

        foreach ($failedChunk as $item) {
            $this->assertSame('failed', $item->status);
            $this->assertSame('Perplexity timeout.', $item->error_message);
            $this->assertNotNull($item->completed_at);
        }

        foreach ($pendingChunk as $item) {
            $this->assertSame('fetching', $item->status);
            $this->assertSame('audit_deep_research_running', $item->extraction_source);
            $this->assertNull($item->error_message);
        }

        $this->assertSame('processing', $run->status);
        Queue::assertPushed(ProcessAuditDeepResearchBatchJob::class, function (ProcessAuditDeepResearchBatchJob $job) use ($pendingChunk): bool {
            return $job->runId === $pendingChunk->first()->audit_run_id
                && $job->itemIds === $pendingChunk->pluck('id')->values()->all();
        });
    }

    public function test_deep_research_failed_batch_retries_recoverable_shape_errors_in_smaller_chunks(): void
    {
        Queue::fake();

        $run = $this->makeRun(totalUrls: 2);
        $items = $this->makeItems($run, 2, 'analyzing', 'audit_deep_research_running');

        $job = new ProcessAuditDeepResearchBatchJob($run->id, $items->pluck('id')->all());
        $job->failed(new RuntimeException('Deep research JSON thiếu dòng kết quả: cần 2, nhận 1.'));

        $items->each->refresh();
        $run->refresh();

        foreach ($items as $item) {
            $this->assertSame('fetching', $item->status);
            $this->assertSame('audit_deep_research_running', $item->extraction_source);
            $this->assertNull($item->error_message);
            $this->assertNull($item->completed_at);
        }

        $this->assertSame('processing', $run->status);
        Queue::assertPushed(ProcessAuditDeepResearchBatchJob::class, 2);
    }

    public function test_deep_research_formatter_repairs_missing_batch_items_before_returning_results(): void
    {
        config([
            'services.perplexity.api_key' => 'test-perplexity-key',
            'services.perplexity.base_url' => 'https://api.perplexity.test',
            'services.openai.api_key' => 'test-openai-key',
        ]);

        $openAiCalls = 0;

        Http::fake(function ($request) use (&$openAiCalls) {
            if ($request->url() === 'https://api.perplexity.test/v1/sonar') {
                return Http::response([
                    'model' => 'sonar-pro',
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'items' => [
                                        $this->researchItem('https://example.com/post-1', 'keyword 1'),
                                        $this->researchItem('https://example.com/post-2', 'keyword 2'),
                                    ],
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ],
                        ],
                    ],
                    'usage' => [
                        'prompt_tokens' => 100,
                        'completion_tokens' => 60,
                        'total_tokens' => 160,
                    ],
                ], 200);
            }

            if ($request->url() === 'https://api.openai.com/v1/responses') {
                $openAiCalls++;

                if ($openAiCalls === 1) {
                    return $this->openAiTextResponse(json_encode([
                        'items' => [
                            $this->auditItem('https://example.com/post-1', 'keyword 1'),
                            $this->auditItem('https://example.com/post-2', 'keyword 2'),
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                if ($openAiCalls === 2) {
                    return $this->openAiTextResponse(json_encode([
                        'items' => [
                            $this->auditItem('https://example.com/post-1', 'keyword 1'),
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                return $this->openAiTextResponse(json_encode([
                    'items' => [
                        $this->auditItem('https://example.com/post-1', 'keyword 1'),
                        $this->auditItem('https://example.com/post-2', 'keyword 2'),
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return Http::response([], 404);
        });

        $result = app(DeepResearchSeoAuditService::class)->analyzeBatch(
            batchPages: [
                $this->deepResearchBatchPage('https://example.com/post-1', 'keyword 1'),
                $this->deepResearchBatchPage('https://example.com/post-2', 'keyword 2'),
            ],
            categories: [],
            categoryContexts: [],
            siteUrls: ['https://example.com/post-1', 'https://example.com/post-2'],
            checklistText: 'Checklist test',
            researchModel: 'sonar-pro',
            reasoningModel: 'gpt-5.5',
            formatterProvider: 'openai',
            formatterModel: 'gpt-5.5',
        );

        $this->assertCount(2, $result['items']);
        $this->assertSame('https://example.com/post-2', $result['items'][1]['targetUrl']);
        $this->assertSame(3, $openAiCalls);
        $this->assertCount(4, $result['usageEvents']);
        $this->assertArrayHasKey('deepResearchFormatterAttempts', $result['promptSnapshots']);
        $this->assertCount(2, $result['promptSnapshots']['deepResearchFormatterAttempts']);
    }

    public function test_create_run_uses_admin_step_3_flow_mode_instead_of_client_payload(): void
    {
        Queue::fake();

        app(AuditSettingsService::class)->updateAuditSettings([
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-5.5',
            'step2AiProvider' => 'openai',
            'step2AiModel' => 'gpt-5.5',
            'step3AiProvider' => 'openai',
            'step3AiModel' => 'gpt-5.5',
            'step2FormatterProvider' => 'openai',
            'step2FormatterModel' => 'gpt-5.5',
            'step3FormatterProvider' => 'openai',
            'step3FormatterModel' => 'gpt-5.5',
            'step3FlowMode' => AuditRun::WORKFLOW_AUDIT_DEEP_RESEARCH,
            'maxParallelItems' => 3,
            'step2BatchSize' => 60,
            'step3BatchSize' => 30,
            'deepResearchBatchSize' => 5,
            'deepResearchResearchProvider' => 'perplexity',
            'deepResearchResearchModel' => 'sonar-deep-research',
            'deepResearchReasoningProvider' => 'openai',
            'deepResearchReasoningModel' => 'gpt-5.5',
            'deepResearchFormatterProvider' => 'openai',
            'deepResearchFormatterModel' => 'gpt-5.5',
        ]);

        $websiteData = Mockery::mock(WebsiteDataService::class);
        $websiteData->shouldReceive('getWebsite')->once()->with('website-live')->andReturn([
            'id' => 'website-live',
            'userId' => 'user-test',
            'name' => 'Website live',
            'url' => 'https://example.com',
        ]);
        $this->app->instance(WebsiteDataService::class, $websiteData);

        $creditService = Mockery::mock(CreditService::class);
        $creditService->shouldReceive('getBalanceUsd')->once()->with('user-test')->andReturn(5.0);
        $this->app->instance(CreditService::class, $creditService);

        $run = app(AuditRunService::class)->createRun('user-test', 'test@example.com', [
            'websiteId' => 'website-live',
            'workflow' => AuditRun::WORKFLOW_STANDARD,
            'targetUrls' => ['https://example.com/post-1'],
            'categories' => [],
            'checklistText' => 'Checklist test',
        ]);

        $run->refresh();

        $this->assertSame(AuditRun::WORKFLOW_AUDIT_DEEP_RESEARCH, $run->workflow);
        Queue::assertPushed(ProcessAuditRunJob::class, function (ProcessAuditRunJob $job) use ($run): bool {
            return $job->runId === $run->id;
        });
    }

    public function test_create_run_from_step_3_prefills_items_from_saved_step_2_data_and_skips_urls_without_seed_data(): void
    {
        Queue::fake();

        app(AuditSettingsService::class)->updateAuditSettings([
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-5.5',
            'step2AiProvider' => 'openai',
            'step2AiModel' => 'gpt-5.5',
            'step3AiProvider' => 'openai',
            'step3AiModel' => 'gpt-5.5',
            'step2FormatterProvider' => 'openai',
            'step2FormatterModel' => 'gpt-5.5',
            'step3FormatterProvider' => 'openai',
            'step3FormatterModel' => 'gpt-5.5',
            'step3FlowMode' => AuditRun::WORKFLOW_STANDARD,
            'maxParallelItems' => 3,
            'step2BatchSize' => 60,
            'step3BatchSize' => 30,
            'deepResearchBatchSize' => 5,
            'deepResearchResearchProvider' => 'perplexity',
            'deepResearchResearchModel' => 'sonar-deep-research',
            'deepResearchReasoningProvider' => 'openai',
            'deepResearchReasoningModel' => 'gpt-5.5',
            'deepResearchFormatterProvider' => 'openai',
            'deepResearchFormatterModel' => 'gpt-5.5',
        ]);

        WebsiteAuditUrlResult::query()->create([
            'website_id' => 'website-live',
            'target_url_hash' => hash('sha256', 'https://example.com/post-1'),
            'target_url' => 'https://example.com/post-1',
            'status' => 'completed',
            'page_title' => 'Post 1',
            'primary_keyword' => 'keyword post 1',
            'category_name' => 'Danh mục A',
            'category_url' => 'https://example.com/danh-muc-a',
            'category_match_reason' => 'Khớp từ bước 2',
        ]);

        WebsiteAuditUrlResult::query()->create([
            'website_id' => 'website-live',
            'target_url_hash' => hash('sha256', 'https://example.com/post-2'),
            'target_url' => 'https://example.com/post-2',
            'status' => 'completed',
            'page_title' => 'Post 2',
            'primary_keyword' => 'keyword post 2',
            'category_name' => null,
            'category_url' => null,
        ]);

        $websiteData = Mockery::mock(WebsiteDataService::class);
        $websiteData->shouldReceive('getWebsite')->once()->with('website-live')->andReturn([
            'id' => 'website-live',
            'userId' => 'user-test',
            'name' => 'Website live',
            'url' => 'https://example.com',
        ]);
        $this->app->instance(WebsiteDataService::class, $websiteData);

        $creditService = Mockery::mock(CreditService::class);
        $creditService->shouldReceive('getBalanceUsd')->once()->with('user-test')->andReturn(5.0);
        $this->app->instance(CreditService::class, $creditService);

        $run = app(AuditRunService::class)->createRun('user-test', 'test@example.com', [
            'websiteId' => 'website-live',
            'startFromStep' => 3,
            'targetUrls' => [
                'https://example.com/post-1',
                'https://example.com/post-2',
            ],
            'categories' => [],
            'checklistText' => 'Checklist test',
        ]);

        $run->refresh();
        $item = $run->items()->firstOrFail();

        $this->assertSame(AuditRun::WORKFLOW_STANDARD, $run->workflow);
        $this->assertSame(['https://example.com/post-1'], $run->target_urls);
        $this->assertSame(1, $run->total_urls);
        $this->assertSame('analyzing', $item->status);
        $this->assertSame('url_only_batch_step2_done', $item->extraction_source);
        $this->assertSame('keyword post 1', $item->primary_keyword);
        $this->assertSame('Danh mục A', $item->category_name);
        $this->assertSame('https://example.com/danh-muc-a', $item->category_url);

        Queue::assertPushed(ProcessAuditRunJob::class, function (ProcessAuditRunJob $job) use ($run): bool {
            return $job->runId === $run->id;
        });
    }

    public function test_create_run_from_step_3_rejects_when_no_urls_have_saved_step_2_data(): void
    {
        Queue::fake();

        $websiteData = Mockery::mock(WebsiteDataService::class);
        $websiteData->shouldReceive('getWebsite')->once()->with('website-live')->andReturn([
            'id' => 'website-live',
            'userId' => 'user-test',
            'name' => 'Website live',
            'url' => 'https://example.com',
        ]);
        $this->app->instance(WebsiteDataService::class, $websiteData);

        $creditService = Mockery::mock(CreditService::class);
        $this->app->instance(CreditService::class, $creditService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Không có URL nào đủ dữ liệu bước 2 để chạy từ bước 3.');

        app(AuditRunService::class)->createRun('user-test', 'test@example.com', [
            'websiteId' => 'website-live',
            'startFromStep' => 3,
            'targetUrls' => ['https://example.com/post-1'],
            'categories' => [],
            'checklistText' => 'Checklist test',
        ]);
    }

    public function test_serialize_run_includes_usage_summary_grouped_by_business_step(): void
    {
        $run = $this->makeRun(totalUrls: 1);
        $item = $this->makeItem($run, 1, 'completed', 'audit_deep_research');

        AiUsageEvent::query()->create([
            'audit_run_item_id' => $item->id,
            'step' => 'deep_research_research_001_001',
            'provider' => 'perplexity',
            'model' => 'sonar-deep-research',
            'input_tokens' => 120,
            'output_tokens' => 80,
            'total_tokens' => 200,
            'citation_tokens' => 40,
            'reasoning_tokens' => 300,
            'search_queries' => 6,
            'provider_reported_cost_usd' => 0.321654,
            'credits_charged' => 9,
            'usd_charged' => 0.321654,
        ]);

        AiUsageEvent::query()->create([
            'audit_run_item_id' => $item->id,
            'step' => 'deep_research_audit_001_001',
            'provider' => 'openai',
            'model' => 'gpt-5.5',
            'input_tokens' => 500,
            'output_tokens' => 150,
            'total_tokens' => 650,
            'citation_tokens' => 0,
            'reasoning_tokens' => 0,
            'search_queries' => 0,
            'provider_reported_cost_usd' => null,
            'credits_charged' => 12,
            'usd_charged' => 0.015,
        ]);

        $payload = app(AuditRunService::class)->serializeRun($run->fresh('items'));

        $this->assertSame('partial', $payload['usageSummary']['costVisibility']);
        $this->assertSame('partial', $payload['usageSummary']['estimateVisibility']);
        $this->assertSame(2, $payload['usageSummary']['totals']['eventCount']);
        $this->assertSame(850, $payload['usageSummary']['totals']['totalTokens']);
        $this->assertSame(300, $payload['usageSummary']['totals']['reasoningTokens']);
        $this->assertSame(6, $payload['usageSummary']['totals']['searchQueries']);
        $this->assertSame(21, $payload['usageSummary']['totals']['creditsCharged']);
        $this->assertEquals(0.336654, $payload['usageSummary']['totals']['usdCharged']);
        $this->assertEquals(0.321654, $payload['usageSummary']['totals']['providerReportedCostUsd']);
        $this->assertEquals(0.03186, $payload['usageSummary']['totals']['estimatedCostUsd']);

        $steps = collect($payload['usageSummary']['byStep'])->keyBy('key');

        $this->assertSame('Bước 3A: research', $steps['deep_research_3a']['label']);
        $this->assertSame(200, $steps['deep_research_3a']['totalTokens']);
        $this->assertEquals(0.321654, $steps['deep_research_3a']['providerReportedCostUsd']);
        $this->assertEquals(0.03186, $steps['deep_research_3a']['estimatedCostUsd']);

        $this->assertSame('Bước 3B: reasoning audit', $steps['deep_research_3b']['label']);
        $this->assertSame(650, $steps['deep_research_3b']['totalTokens']);
        $this->assertNull($steps['deep_research_3b']['providerReportedCostUsd']);
        $this->assertNull($steps['deep_research_3b']['estimatedCostUsd']);
    }

    private function deepResearchBatchPage(string $url, string $keyword): array
    {
        return [
            'targetUrl' => $url,
            'page' => [
                'url' => $url,
                'title' => 'Title '.$keyword,
                'metaDescription' => 'Meta '.$keyword,
                'canonicalUrl' => $url,
                'headings' => ['h1' => ['Title '.$keyword], 'h2' => ['Heading '.$keyword]],
                'metrics' => ['wordCount' => 1200, 'imageCount' => 2],
                'content' => 'Article content '.$keyword,
                'websiteUrl' => 'https://example.com',
            ],
            'primaryKeywordSeed' => $keyword,
            'categoryNameSeed' => 'Danh mục test',
            'categoryUrlSeed' => 'https://example.com/category',
        ];
    }

    private function researchItem(string $url, string $keyword): array
    {
        return [
            'targetUrl' => $url,
            'primaryKeyword' => $keyword,
            'categoryName' => 'Danh mục test',
            'categoryUrl' => 'https://example.com/category',
            'categoryMatchReason' => 'Khớp seed từ bước 2',
            'searchIntent' => 'Commercial',
            'competitorInsights' => ['Đối thủ có bảng giá và FAQ'],
            'freshnessInsights' => ['Cần cập nhật dữ liệu 2026'],
            'keywordDemandEvidence' => 'SERP có nhu cầu rõ ràng',
            'contentGapInsights' => ['Thiếu FAQ'],
            'recommendedAngles' => ['Thêm bảng so sánh'],
            'sources' => [],
        ];
    }

    private function auditItem(string $url, string $keyword): array
    {
        return [
            'targetUrl' => $url,
            'primaryKeyword' => $keyword,
            'categoryName' => 'Danh mục test',
            'categoryUrl' => 'https://example.com/category',
            'categoryMatchReason' => 'Khớp seed từ bước 2',
            'auditScore' => 80,
            'auditFindings' => [
                'Điểm kỹ thuật SEO: 18/24',
                'Điểm nội dung: 5/6',
                'STT 7: Keyword xuất hiện trong các vị trí chính',
                'STT 23: Có dữ liệu cập nhật năm hiện tại',
            ],
            'auditRecommendations' => [
                'Bổ sung internal link liên quan cho bài viết',
                'Thêm section Q&A cuối bài',
                'Tối ưu lại meta description khoảng 140 ký tự',
                'Bổ sung bảng so sánh để tăng khả năng chuyển đổi',
            ],
            'contentRevisionDirection' => 'Audit Content. Bài viết đã có nền tảng SEO tương đối tốt. Cần bổ sung internal link, Q&A và dữ liệu mới để tăng độ phủ. Ưu tiên tối ưu các tiêu chí mất điểm nhiều nhất trước.',
        ];
    }

    private function openAiTextResponse(string $text)
    {
        return Http::response([
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['text' => $text],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 50,
                'output_tokens' => 50,
            ],
        ], 200);
    }

    private function makeRun(int $totalUrls = 1, array $overrides = []): AuditRun
    {
        return AuditRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => 'website-test',
            'website_name' => 'Website test',
            'website_url' => 'https://example.com',
            'user_uid' => 'user-test',
            'user_email' => 'test@example.com',
            'status' => 'processing',
            'workflow' => AuditRun::WORKFLOW_AUDIT_DEEP_RESEARCH,
            'target_urls' => array_map(fn (int $index): string => "https://example.com/post-{$index}", range(1, $totalUrls)),
            'categories' => [
                ['name' => 'Trường Anh ngữ IMS', 'url' => 'https://example.com/ims'],
            ],
            'category_contexts' => [],
            'checklist_text' => 'Checklist test',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-5.5',
            'step2_ai_provider' => 'openai',
            'step2_ai_model' => 'gpt-5.5',
            'step3_ai_provider' => 'openai',
            'step3_ai_model' => 'gpt-5.5',
            'step2_formatter_provider' => 'openai',
            'step2_formatter_model' => 'gpt-5.5',
            'step3_formatter_provider' => 'openai',
            'step3_formatter_model' => 'gpt-5.5',
            'deep_research_research_provider' => 'perplexity',
            'deep_research_research_model' => 'sonar-deep-research',
            'deep_research_reasoning_provider' => 'openai',
            'deep_research_reasoning_model' => 'gpt-5.5',
            'deep_research_formatter_provider' => 'openai',
            'deep_research_formatter_model' => 'gpt-5.5',
            'total_urls' => $totalUrls,
            'processed_urls' => 0,
            'completed_urls' => 0,
            'failed_urls' => 0,
            'started_at' => now(),
            ...$overrides,
        ]);
    }

    private function makeItem(AuditRun $run, int $position, string $status, ?string $source): AuditRunItem
    {
        return AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => $position,
            'target_url' => "https://example.com/post-{$position}",
            'status' => $status,
            'extraction_source' => $source,
            'primary_keyword' => 'ims keyword from step 2',
            'category_name' => 'Trường Anh ngữ IMS',
            'category_url' => 'https://example.com/ims',
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, AuditRunItem>
     */
    private function makeItems(AuditRun $run, int $count, string $status, ?string $source, int $startPosition = 1)
    {
        return collect(range($startPosition, $startPosition + $count - 1))
            ->map(function (int $position) use ($run, $source, $status): AuditRunItem {
                return AuditRunItem::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'audit_run_id' => $run->id,
                    'position' => $position,
                    'target_url' => "https://example.com/post-{$position}",
                    'status' => $status,
                    'extraction_source' => $source,
                    'primary_keyword' => 'ims keyword from step 2',
                    'category_name' => 'Trường Anh ngữ IMS',
                    'category_url' => 'https://example.com/ims',
                ]);
            });
    }
}
