<?php

namespace Tests\Feature;

use App\Jobs\ProcessAuditRunStep2BatchJob;
use App\Models\AuditRun;
use App\Models\AuditRunItem;
use App\Services\AuditRunService;
use App\Services\SeoAiAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AuditStep1WorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_step1_only_run_finalizes_after_step1_without_dispatching_step2(): void
    {
        Queue::fake();

        $run = $this->makeRun([
            'stop_after_step' => 1,
            'target_urls' => [
                'https://example.com/post-1',
                'https://example.com/post-2',
            ],
            'total_urls' => 2,
        ]);
        $items = $this->makeItems($run, 2, 'queued', 'url_only_batch_step1_done');

        app(AuditRunService::class)->dispatchStep1Batches($run);

        $run->refresh();
        $items->each->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->processed_urls);
        $this->assertSame(2, $run->completed_urls);
        $this->assertSame(0, $run->failed_urls);

        foreach ($items as $item) {
            $this->assertSame('completed', $item->status);
            $this->assertSame('url_only_batch_step1_only_completed', $item->extraction_source);
            $this->assertNotNull($item->completed_at);
        }

        Queue::assertNotPushed(ProcessAuditRunStep2BatchJob::class);
    }

    public function test_process_step2_batch_passes_step1_payload_into_batch_ai_call(): void
    {
        Queue::fake();

        $run = $this->makeRun([
            'stop_after_step' => 2,
            'categories' => [
                ['name' => 'Phế liệu đồng', 'url' => 'https://example.com/phe-lieu-dong'],
            ],
        ]);
        $item = AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => 1,
            'target_url' => 'https://example.com/day-cap-dien-phe-lieu',
            'status' => 'fetching',
            'extraction_source' => 'url_only_batch_step2_running',
            'content_source' => 'jina',
            'content_error' => null,
            'page_title' => 'Dây cáp điện phế liệu giá cao tại TPHCM',
            'meta_description' => 'Thu mua dây cáp điện phế liệu giá cao nhất tại TP.HCM.',
            'canonical_url' => 'https://example.com/day-cap-dien-phe-lieu',
            'extracted_headings' => [
                'h1' => ['Dây cáp điện phế liệu giá cao tại TPHCM'],
                'h2' => ['Thiên Long thu mua tận nơi'],
                'h3' => [],
            ],
            'extracted_metrics' => [
                'wordCount' => 1280,
                'imageCount' => 3,
            ],
            'content_excerpt' => 'Thu mua dây cáp điện phế liệu giá cao nhất tại TP.HCM. Thiên Long chuyên thu mua tận nơi, nhanh chóng, uy tín.',
        ]);

        $seoAi = Mockery::mock(SeoAiAuditService::class);
        $seoAi->shouldReceive('analyzeBatchKeywordCategoryUrlOnly')
            ->once()
            ->withArgs(function (
                array $targetUrls,
                array $categories,
                string $provider,
                ?string $model,
                ?string $formatterProvider,
                ?string $formatterModel,
                ?int $auditRunId,
                ?string $persistStep,
                array $batchPages
            ) use ($item, $run): bool {
                return $targetUrls === [$item->target_url]
                    && $categories === $run->categories
                    && $provider === 'openai'
                    && $model === 'gpt-4.1'
                    && $formatterProvider === 'gemini'
                    && $formatterModel === 'gemini-2.5-flash'
                    && $auditRunId === $run->id
                    && ($persistStep !== null && str_starts_with($persistStep, 'batch_keyword_category_mapping_'))
                    && ($batchPages[0]['targetUrl'] ?? null) === $item->target_url
                    && ($batchPages[0]['page']['title'] ?? null) === 'Dây cáp điện phế liệu giá cao tại TPHCM'
                    && ($batchPages[0]['page']['metaDescription'] ?? null) === 'Thu mua dây cáp điện phế liệu giá cao nhất tại TP.HCM.'
                    && ($batchPages[0]['page']['source'] ?? null) === 'jina'
                    && str_contains((string) ($batchPages[0]['articleContent'] ?? ''), 'Thiên Long chuyên thu mua tận nơi');
            })
            ->andReturn([
                'items' => [
                    [
                        'targetUrl' => $item->target_url,
                        'primaryKeyword' => 'thu mua dây cáp điện phế liệu giá cao tại tphcm',
                        'categoryName' => 'Phế liệu đồng',
                        'categoryUrl' => 'https://example.com/phe-lieu-dong',
                        'categoryMatchReason' => 'Title và excerpt nói rõ dây cáp điện phế liệu.',
                    ],
                ],
                'promptSnapshot' => ['step' => 'keyword_category_mapping'],
                'formatterPromptSnapshot' => null,
                'usageEvents' => [],
            ]);
        $this->app->instance(SeoAiAuditService::class, $seoAi);

        app(AuditRunService::class)->processStep2Batch($run, [$item->id]);

        $item->refresh();

        $this->assertSame('completed', $item->status);
        $this->assertSame('url_only_batch_step2_only_completed', $item->extraction_source);
        $this->assertSame('thu mua dây cáp điện phế liệu giá cao tại tphcm', $item->primary_keyword);
        $this->assertSame('Phế liệu đồng', $item->category_name);
        $this->assertSame('https://example.com/phe-lieu-dong', $item->category_url);
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

    /**
     * @return \Illuminate\Support\Collection<int, AuditRunItem>
     */
    private function makeItems(AuditRun $run, int $count, string $status, ?string $source = null): \Illuminate\Support\Collection
    {
        $items = collect();

        for ($index = 1; $index <= $count; $index++) {
            $items->push(AuditRunItem::query()->create([
                'public_id' => (string) Str::ulid(),
                'audit_run_id' => $run->id,
                'position' => $index,
                'target_url' => "https://example.com/post-{$index}",
                'status' => $status,
                'extraction_source' => $source,
            ]));
        }

        return $items;
    }
}
