<?php

namespace Tests\Feature;

use App\Jobs\ProcessAuditRunStep2BatchJob;
use App\Models\AuditRun;
use App\Models\AuditRunItem;
use App\Services\AuditRunService;
use App\Services\AuditSettingsService;
use App\Services\SeoAiAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AuditFastModePartialBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fast_mode_accepts_partial_batch_and_dispatches_next_chunk(): void
    {
        Queue::fake();

        app(AuditSettingsService::class)->updateAuditSettings([
            'auditPipelineMode' => AuditRun::PIPELINE_FAST,
            'fastBatchSize' => 2,
            'maxParallelItems' => 2,
            'fastAiProvider' => 'openai',
            'fastAiModel' => 'gpt-5.5',
            'fastFormatterProvider' => 'openai',
            'fastFormatterModel' => 'gpt-5.5',
        ]);

        $run = $this->makeRun();
        [$first, $second, $third, $fourth] = $this->makeItems($run);

        $seoAi = Mockery::mock(SeoAiAuditService::class);
        $seoAi->shouldReceive('analyzeBatchFastAudit')->once()->andReturn([
            'items' => [
                [
                    'targetUrl' => $first->target_url,
                    'primaryKeyword' => 'thu mua phế liệu fast mode',
                    'categoryName' => 'Thu mua phế liệu',
                    'categoryUrl' => 'https://example.com/thu-mua',
                    'categoryMatchReason' => 'Khớp slug và nội dung bước 1.',
                    'auditScore' => 74,
                    'auditFindings' => [
                        'Điểm kỹ thuật SEO: 18/24',
                        'Điểm nội dung: 4/6',
                        'STT 7: Keyword xuất hiện đúng trong URL',
                        'STT 23: Có cập nhật xu hướng năm hiện tại',
                    ],
                    'auditRecommendations' => [
                        'Bổ sung thêm 1 internal link liên quan',
                        'Mở rộng FAQ cuối bài',
                        'Thêm CTA rõ hơn ở giữa bài',
                        'Tăng thêm dữ liệu so sánh với đối thủ',
                    ],
                    'contentRevisionDirection' => 'Audit Content. Bài viết đã có nền tảng đúng intent. Cần tối ưu thêm internal link và CTA để tăng hiệu quả SEO. Ưu tiên giữ cấu trúc hiện tại và cập nhật thêm dữ liệu mới.',
                ],
            ],
            'promptSnapshot' => [],
            'formatterPromptSnapshot' => null,
            'usageEvents' => [],
        ]);
        $this->app->instance(SeoAiAuditService::class, $seoAi);

        app(AuditRunService::class)->processStep2Batch($run, [$first->id, $second->id]);

        $first->refresh();
        $second->refresh();
        $third->refresh();
        $fourth->refresh();
        $run->refresh();

        $this->assertSame('completed', $first->status);
        $this->assertSame('url_only_batch', $first->extraction_source);
        $this->assertSame(74, $first->audit_score);

        $this->assertSame('failed', $second->status);
        $this->assertStringContainsString('Batch AI không trả kết quả cho URL này', (string) $second->error_message);

        $this->assertSame('fetching', $third->status);
        $this->assertSame('url_only_batch_step2_running', $third->extraction_source);
        $this->assertSame('fetching', $fourth->status);
        $this->assertSame('url_only_batch_step2_running', $fourth->extraction_source);

        $this->assertSame('processing', $run->status);
        $this->assertSame(2, $run->processed_urls);
        $this->assertSame(1, $run->completed_urls);
        $this->assertSame(1, $run->failed_urls);

        Queue::assertPushed(ProcessAuditRunStep2BatchJob::class, function (ProcessAuditRunStep2BatchJob $job) use ($run, $third, $fourth): bool {
            return $job->runId === $run->id
                && $job->itemIds === [$third->id, $fourth->id];
        });
    }

    public function test_fast_mode_maps_formatter_results_by_target_url_not_by_index(): void
    {
        Queue::fake();

        app(AuditSettingsService::class)->updateAuditSettings([
            'auditPipelineMode' => AuditRun::PIPELINE_FAST,
            'fastBatchSize' => 2,
            'maxParallelItems' => 2,
            'fastAiProvider' => 'openai',
            'fastAiModel' => 'gpt-5.5',
            'fastFormatterProvider' => 'openai',
            'fastFormatterModel' => 'gpt-5.5',
        ]);

        $run = $this->makeRun();
        [$first, $second] = array_slice($this->makeItems($run), 0, 2);

        $seoAi = Mockery::mock(SeoAiAuditService::class);
        $seoAi->shouldReceive('analyzeBatchFastAudit')->once()->andReturn([
            'items' => [
                [
                    'targetUrl' => $second->target_url,
                    'primaryKeyword' => 'keyword second',
                    'categoryName' => 'Danh mục 2',
                    'categoryUrl' => 'https://example.com/cat-2',
                    'categoryMatchReason' => 'Matched second URL.',
                    'auditScore' => 22,
                    'auditFindings' => [
                        'Điểm kỹ thuật SEO: 6/24',
                        'Điểm nội dung: 1/6',
                        'STT 7: Thiếu keyword trong H2',
                        'STT 15: Thiếu internal link',
                    ],
                    'auditRecommendations' => [
                        'Fix second title',
                        'Fix second internal links',
                        'Fix second FAQ',
                        'Fix second CTA',
                    ],
                    'contentRevisionDirection' => 'Viết lại. URL thứ hai cần chỉnh lại gần như toàn bộ. Ưu tiên tối ưu nội dung và cấu trúc. Sau đó bổ sung liên kết nội bộ.',
                ],
                [
                    'targetUrl' => $first->target_url,
                    'primaryKeyword' => 'keyword first',
                    'categoryName' => 'Danh mục 1',
                    'categoryUrl' => 'https://example.com/cat-1',
                    'categoryMatchReason' => 'Matched first URL.',
                    'auditScore' => 81,
                    'auditFindings' => [
                        'Điểm kỹ thuật SEO: 20/24',
                        'Điểm nội dung: 5/6',
                        'STT 7: Keyword placement tốt',
                        'STT 23: Có freshness',
                    ],
                    'auditRecommendations' => [
                        'Keep first title',
                        'Add one more image',
                        'Expand FAQ',
                        'Sharpen CTA',
                    ],
                    'contentRevisionDirection' => 'Giữ nguyên. URL thứ nhất đã khá tốt và đúng intent. Chỉ cần tinh chỉnh nhẹ CTA và hình ảnh. Ưu tiên giữ nguyên cấu trúc hiện tại.',
                ],
            ],
            'promptSnapshot' => [],
            'formatterPromptSnapshot' => null,
            'usageEvents' => [],
        ]);
        $this->app->instance(SeoAiAuditService::class, $seoAi);

        app(AuditRunService::class)->processStep2Batch($run, [$first->id, $second->id]);

        $first->refresh();
        $second->refresh();

        $this->assertSame('completed', $first->status);
        $this->assertSame('completed', $second->status);
        $this->assertSame(81, $first->audit_score);
        $this->assertSame(22, $second->audit_score);
        $this->assertSame('Danh mục 1', $first->category_name);
        $this->assertSame('Danh mục 2', $second->category_name);
        $this->assertSame('keyword first', $first->primary_keyword);
        $this->assertSame('keyword second', $second->primary_keyword);
    }

    public function test_fast_mode_gemini_shrinks_batch_by_count_tokens_before_calling_ai(): void
    {
        Queue::fake();

        config()->set('services.audit.gemini_fast_input_token_soft_limit', 190000);

        app(AuditSettingsService::class)->updateAuditSettings([
            'auditPipelineMode' => AuditRun::PIPELINE_FAST,
            'fastBatchSize' => 4,
            'maxParallelItems' => 2,
            'fastAiProvider' => 'gemini',
            'fastAiModel' => 'gemini-2.5-pro',
            'fastFormatterProvider' => 'gemini',
            'fastFormatterModel' => 'gemini-2.5-flash',
        ]);

        $run = $this->makeRun();
        $run->forceFill([
            'step2_ai_provider' => 'gemini',
            'step2_ai_model' => 'gemini-2.5-pro',
            'step2_formatter_provider' => 'gemini',
            'step2_formatter_model' => 'gemini-2.5-flash',
        ])->save();

        [$first, $second, $third, $fourth] = $this->makeItems($run);

        AuditRunItem::query()
            ->whereIn('id', [$third->id, $fourth->id])
            ->update([
                'status' => 'fetching',
                'extraction_source' => 'url_only_batch_step2_running',
            ]);

        $seoAi = Mockery::mock(SeoAiAuditService::class);
        $seoAi->shouldReceive('countFastAuditGeminiInputTokens')
            ->times(3)
            ->andReturn(210000, 205000, 180000);
        $seoAi->shouldReceive('analyzeBatchFastAudit')
            ->once()
            ->withArgs(function (
                array $targetUrls,
                array $categories,
                ?string $checklistText,
                string $provider,
                ?string $model,
                ...$rest
            ) use ($first, $second): bool {
                return $targetUrls === [$first->target_url, $second->target_url]
                    && $provider === 'gemini'
                    && $model === 'gemini-2.5-pro';
            })
            ->andReturn([
                'items' => [
                    [
                        'targetUrl' => $first->target_url,
                        'primaryKeyword' => 'keyword first',
                        'categoryName' => 'Danh mục 1',
                        'categoryUrl' => 'https://example.com/cat-1',
                        'categoryMatchReason' => 'Matched first URL.',
                        'auditScore' => 81,
                        'auditFindings' => [
                            'Điểm kỹ thuật SEO: 20/24',
                            'Điểm nội dung: 5/6',
                            'STT 7: Keyword placement tốt',
                            'STT 23: Có freshness',
                        ],
                        'auditRecommendations' => [
                            'Keep first title',
                            'Add one more image',
                            'Expand FAQ',
                            'Sharpen CTA',
                        ],
                        'contentRevisionDirection' => 'Giữ nguyên. URL thứ nhất đã khá tốt và đúng intent. Chỉ cần tinh chỉnh nhẹ CTA và hình ảnh. Ưu tiên giữ nguyên cấu trúc hiện tại.',
                    ],
                    [
                        'targetUrl' => $second->target_url,
                        'primaryKeyword' => 'keyword second',
                        'categoryName' => 'Danh mục 2',
                        'categoryUrl' => 'https://example.com/cat-2',
                        'categoryMatchReason' => 'Matched second URL.',
                        'auditScore' => 22,
                        'auditFindings' => [
                            'Điểm kỹ thuật SEO: 6/24',
                            'Điểm nội dung: 1/6',
                            'STT 7: Thiếu keyword trong H2',
                            'STT 15: Thiếu internal link',
                        ],
                        'auditRecommendations' => [
                            'Fix second title',
                            'Fix second internal links',
                            'Fix second FAQ',
                            'Fix second CTA',
                        ],
                        'contentRevisionDirection' => 'Viết lại. URL thứ hai cần chỉnh lại gần như toàn bộ. Ưu tiên tối ưu nội dung và cấu trúc. Sau đó bổ sung liên kết nội bộ.',
                    ],
                ],
                'promptSnapshot' => [],
                'formatterPromptSnapshot' => null,
                'usageEvents' => [],
            ]);
        $this->app->instance(SeoAiAuditService::class, $seoAi);

        app(AuditRunService::class)->processStep2Batch($run, [$first->id, $second->id, $third->id, $fourth->id]);

        $first->refresh();
        $second->refresh();
        $third->refresh();
        $fourth->refresh();

        $this->assertSame('completed', $first->status);
        $this->assertSame('completed', $second->status);
        $this->assertSame('fetching', $third->status);
        $this->assertSame('fetching', $fourth->status);
        $this->assertSame('url_only_batch_step2_running', $third->extraction_source);
        $this->assertSame('url_only_batch_step2_running', $fourth->extraction_source);

        Queue::assertPushed(ProcessAuditRunStep2BatchJob::class, function (ProcessAuditRunStep2BatchJob $job) use ($run, $third, $fourth): bool {
            return $job->runId === $run->id
                && $job->itemIds === [$third->id, $fourth->id];
        });
    }

    private function makeRun(): AuditRun
    {
        return AuditRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => 'website-fast',
            'website_name' => 'Website fast',
            'website_url' => 'https://example.com',
            'user_uid' => 'user-fast',
            'user_email' => 'fast@example.com',
            'status' => 'processing',
            'workflow' => AuditRun::WORKFLOW_STANDARD,
            'pipeline_mode' => AuditRun::PIPELINE_FAST,
            'target_urls' => [
                'https://example.com/post-1',
                'https://example.com/post-2',
                'https://example.com/post-3',
                'https://example.com/post-4',
            ],
            'categories' => [
                ['name' => 'Thu mua phế liệu', 'url' => 'https://example.com/thu-mua'],
            ],
            'checklist_text' => 'Checklist fast mode test',
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
            'total_urls' => 4,
            'processed_urls' => 0,
            'completed_urls' => 0,
            'failed_urls' => 0,
            'started_at' => now(),
        ]);
    }

    /**
     * @return array<int, AuditRunItem>
     */
    private function makeItems(AuditRun $run): array
    {
        $first = AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => 1,
            'target_url' => 'https://example.com/post-1',
            'status' => 'fetching',
            'extraction_source' => 'url_only_batch_step2_running',
            'content_source' => 'html',
            'page_title' => 'Post 1',
            'content_excerpt' => 'Excerpt 1',
        ]);
        $second = AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => 2,
            'target_url' => 'https://example.com/post-2',
            'status' => 'fetching',
            'extraction_source' => 'url_only_batch_step2_running',
            'content_source' => 'html',
            'page_title' => 'Post 2',
            'content_excerpt' => 'Excerpt 2',
        ]);
        $third = AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => 3,
            'target_url' => 'https://example.com/post-3',
            'status' => 'queued',
            'content_source' => 'html',
            'page_title' => 'Post 3',
            'content_excerpt' => 'Excerpt 3',
        ]);
        $fourth = AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => 4,
            'target_url' => 'https://example.com/post-4',
            'status' => 'queued',
            'content_source' => 'html',
            'page_title' => 'Post 4',
            'content_excerpt' => 'Excerpt 4',
        ]);

        return [$first, $second, $third, $fourth];
    }
}
