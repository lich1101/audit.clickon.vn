<?php

namespace Tests\Feature;

use App\Jobs\ProcessAuditDeepResearchBatchJob;
use App\Jobs\ProcessAuditRunItemJob;
use App\Jobs\ProcessAuditRunStep3BatchJob;
use App\Models\AuditRun;
use App\Models\AuditRunItem;
use App\Services\AuditSettingsService;
use App\Services\AuditRunService;
use App\Services\DeepResearchSeoAuditService;
use App\Services\SeoContentExtractionService;
use App\Services\TokenBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ?string $researchModel,
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
                && $researchModel === 'sonar-pro'
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
                'research' => ['provider' => 'perplexity', 'model' => 'sonar-pro'],
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
            'deepResearchResearchModel' => 'sonar-pro',
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

    private function makeRun(int $totalUrls = 1): AuditRun
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
            'deep_research_research_model' => 'sonar-pro',
            'deep_research_reasoning_model' => 'gpt-5.5',
            'deep_research_formatter_provider' => 'openai',
            'deep_research_formatter_model' => 'gpt-5.5',
            'total_urls' => $totalUrls,
            'processed_urls' => 0,
            'completed_urls' => 0,
            'failed_urls' => 0,
            'started_at' => now(),
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
