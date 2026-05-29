<?php

namespace Tests\Feature;

use App\Jobs\ProcessAuditRunStep2BatchJob;
use App\Jobs\ProcessAuditRunStep3BatchJob;
use App\Models\AuditRun;
use App\Models\AuditRunItem;
use App\Models\WebsiteAuditUrlResult;
use App\Services\AuditRunService;
use App\Services\AuditSettingsService;
use App\Services\SeoAiAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AuditStep1GateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AuditSettingsService::class)->updateAuditSettings([
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-4.1',
            'step2AiProvider' => 'openai',
            'step2AiModel' => 'gpt-4.1',
            'step3AiProvider' => 'openai',
            'step3AiModel' => 'gpt-4.1',
            'step2FormatterProvider' => 'gemini',
            'step2FormatterModel' => 'gemini-2.5-flash',
            'step3FormatterProvider' => 'gemini',
            'step3FormatterModel' => 'gemini-2.5-flash',
            'step3FlowMode' => 'standard',
            'maxParallelItems' => 5,
            'step2BatchSize' => 50,
            'step3BatchSize' => 50,
            'minValidUrlsAfterStep1' => 50,
            'deepResearchBatchSize' => 5,
            'deepResearchResearchProvider' => 'perplexity',
            'deepResearchResearchModel' => 'sonar-deep-research',
            'deepResearchReasoningProvider' => 'openai',
            'deepResearchReasoningModel' => 'gpt-5.5',
            'deepResearchFormatterProvider' => 'openai',
            'deepResearchFormatterModel' => 'gpt-5.5',
        ]);
    }

    public function test_step1_gate_stops_run_when_valid_urls_below_minimum(): void
    {
        Queue::fake();

        $run = $this->makeRun(['total_urls' => 60]);
        $service = app(AuditRunService::class);

        for ($index = 1; $index <= 40; $index++) {
            $this->makeStep1DoneItem($run, $index, valid: true);
        }

        for ($index = 41; $index <= 60; $index++) {
            $this->makeStep1DoneItem($run, $index, valid: false);
        }

        $service->dispatchStep1Batches($run);

        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('thấp hơn mức tối thiểu 50 URL', (string) $run->last_error);
        $this->assertSame(40, AuditRunItem::query()->where('audit_run_id', $run->id)->where('status', 'failed')->count());
        Queue::assertNotPushed(ProcessAuditRunStep2BatchJob::class);
    }

    public function test_step1_gate_passes_valid_urls_to_step2_when_minimum_met(): void
    {
        Queue::fake();

        $run = $this->makeRun(['total_urls' => 55]);
        $service = app(AuditRunService::class);

        for ($index = 1; $index <= 55; $index++) {
            $this->makeStep1DoneItem($run, $index, valid: true);
        }

        $service->dispatchStep1Batches($run);

        $run->refresh();

        $this->assertSame('processing', $run->status);
        $this->assertSame(55, AuditRunItem::query()
            ->where('audit_run_id', $run->id)
            ->where('status', 'fetching')
            ->where('extraction_source', 'url_only_batch_step2_running')
            ->count());
        Queue::assertPushed(ProcessAuditRunStep2BatchJob::class, 2);
    }

    public function test_step2_failure_aborts_run_and_does_not_dispatch_step3_when_batch_sizes_differ(): void
    {
        Queue::fake();

        app(AuditSettingsService::class)->updateAuditSettings([
            'step2BatchSize' => 50,
            'step3BatchSize' => 30,
        ]);

        $run = $this->makeRun([
            'stop_after_step' => null,
            'categories' => [
                ['name' => 'Danh mục A', 'url' => 'https://example.com/a'],
            ],
        ]);

        $validItem = $this->makeStep1DoneItem($run, 1, valid: true);
        $validItem->forceFill([
            'status' => 'fetching',
            'extraction_source' => 'url_only_batch_step2_running',
        ])->save();

        $failedItem = $this->makeStep1DoneItem($run, 2, valid: true);
        $failedItem->forceFill([
            'status' => 'fetching',
            'extraction_source' => 'url_only_batch_step2_running',
        ])->save();

        $seoAi = Mockery::mock(SeoAiAuditService::class);
        $seoAi->shouldReceive('analyzeBatchKeywordCategoryUrlOnly')
            ->once()
            ->andReturn([
                'items' => [
                    [
                        'targetUrl' => $validItem->target_url,
                        'primaryKeyword' => 'keyword a',
                        'categoryName' => 'Danh mục A',
                        'categoryUrl' => 'https://example.com/a',
                    ],
                ],
                'promptSnapshot' => null,
                'formatterPromptSnapshot' => null,
                'usageEvents' => [],
            ]);
        $this->app->instance(SeoAiAuditService::class, $seoAi);

        app(AuditRunService::class)->processStep2Batch($run, [$validItem->id, $failedItem->id]);

        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Bước 2 có URL lỗi', (string) $run->last_error);
        Queue::assertNotPushed(ProcessAuditRunStep3BatchJob::class);
    }

    public function test_step2_pipeline_dispatches_step3_for_successful_urls_when_batch_sizes_match(): void
    {
        Queue::fake();

        app(AuditSettingsService::class)->updateAuditSettings([
            'step2BatchSize' => 50,
            'step3BatchSize' => 50,
        ]);

        $run = $this->makeRun([
            'categories' => [
                ['name' => 'Danh mục A', 'url' => 'https://example.com/a'],
            ],
        ]);

        $validItem = $this->makeStep1DoneItem($run, 1, valid: true);
        $validItem->forceFill([
            'status' => 'fetching',
            'extraction_source' => 'url_only_batch_step2_running',
        ])->save();

        $failedItem = $this->makeStep1DoneItem($run, 2, valid: true);
        $failedItem->forceFill([
            'status' => 'fetching',
            'extraction_source' => 'url_only_batch_step2_running',
        ])->save();

        $seoAi = Mockery::mock(SeoAiAuditService::class);
        $seoAi->shouldReceive('analyzeBatchKeywordCategoryUrlOnly')
            ->once()
            ->andReturn([
                'items' => [
                    [
                        'targetUrl' => $validItem->target_url,
                        'primaryKeyword' => 'keyword a',
                        'categoryName' => 'Danh mục A',
                        'categoryUrl' => 'https://example.com/a',
                    ],
                ],
                'promptSnapshot' => null,
                'formatterPromptSnapshot' => null,
                'usageEvents' => [],
            ]);
        $this->app->instance(SeoAiAuditService::class, $seoAi);

        app(AuditRunService::class)->processStep2Batch($run, [$validItem->id, $failedItem->id]);

        $run->refresh();
        $validItem->refresh();
        $failedItem->refresh();

        $this->assertSame('processing', $run->status);
        $this->assertSame('analyzing', $validItem->status);
        $this->assertSame('url_only_batch_step3_running', $validItem->extraction_source);
        $this->assertSame('failed', $failedItem->status);
        Queue::assertPushed(ProcessAuditRunStep3BatchJob::class, 1);
    }

    public function test_is_step1_valid_url_result_rejects_url_only_and_404(): void
    {
        $service = app(AuditRunService::class);

        $urlOnly = WebsiteAuditUrlResult::query()->make([
            'content_source' => 'url_only',
            'content_error' => null,
            'page_title' => null,
            'meta_description' => null,
            'content_excerpt' => null,
        ]);

        $notFound = WebsiteAuditUrlResult::query()->make([
            'content_source' => 'jina',
            'content_error' => 'HTTP 404 Not Found',
            'page_title' => 'Missing',
            'meta_description' => null,
            'content_excerpt' => 'short',
        ]);

        $titleOnly = WebsiteAuditUrlResult::query()->make([
            'content_source' => 'jina',
            'content_error' => null,
            'page_title' => 'Tiêu đề hợp lệ',
            'meta_description' => null,
            'content_excerpt' => null,
        ]);

        $valid = WebsiteAuditUrlResult::query()->make([
            'content_source' => 'jina',
            'content_error' => null,
            'page_title' => 'Tiêu đề hợp lệ',
            'meta_description' => null,
            'content_excerpt' => str_repeat('Nội dung bài viết đủ dài để audit SEO onpage theo checklist Clickon. ', 20),
            'extracted_metrics' => ['auditReady' => true],
        ]);

        $this->assertFalse($service->isStep1ValidUrlResult($urlOnly));
        $this->assertFalse($service->isStep1ValidUrlResult($notFound));
        $this->assertFalse($service->isStep1ValidUrlResult($titleOnly));
        $this->assertTrue($service->isStep1ValidUrlResult($valid));
    }

    private function makeRun(array $overrides = []): AuditRun
    {
        return AuditRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => 'website-test',
            'website_name' => 'Website test',
            'website_url' => 'https://example.com',
            'user_uid' => 'user-test',
            'user_email' => 'test@example.com',
            'status' => 'processing',
            'workflow' => AuditRun::WORKFLOW_STANDARD,
            'start_from_step' => 1,
            'target_urls' => ['https://example.com/post-1'],
            'categories' => [],
            'category_contexts' => [],
            'checklist_text' => 'Checklist test',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4.1',
            'step2_ai_provider' => 'openai',
            'step2_ai_model' => 'gpt-4.1',
            'step3_ai_provider' => 'openai',
            'step3_ai_model' => 'gpt-4.1',
            'step2_formatter_provider' => 'gemini',
            'step2_formatter_model' => 'gemini-2.5-flash',
            'step3_formatter_provider' => 'gemini',
            'step3_formatter_model' => 'gemini-2.5-flash',
            'total_urls' => 1,
            'processed_urls' => 0,
            'completed_urls' => 0,
            'failed_urls' => 0,
            'started_at' => now(),
            ...$overrides,
        ]);
    }

    private function makeStep1DoneItem(AuditRun $run, int $index, bool $valid): AuditRunItem
    {
        return AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => $index,
            'target_url' => "https://example.com/post-{$index}",
            'status' => 'queued',
            'extraction_source' => 'url_only_batch_step1_done',
            'content_source' => $valid ? 'jina' : 'url_only',
            'content_error' => $valid ? null : 'Could not extract content',
            'page_title' => $valid ? "Tiêu đề bài {$index}" : null,
            'meta_description' => $valid ? 'Mô tả meta hợp lệ cho bài viết.' : null,
            'content_excerpt' => $valid ? str_repeat('Nội dung crawl hợp lệ đủ dài cho audit SEO onpage theo checklist Clickon. ', 20) : null,
            'extracted_metrics' => $valid ? ['auditReady' => true, 'wordCount' => 160] : null,
        ]);
    }
}
