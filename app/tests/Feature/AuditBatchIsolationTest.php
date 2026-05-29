<?php

namespace Tests\Feature;

use App\Jobs\ProcessAuditRunStep3BatchJob;
use App\Models\AuditRun;
use App\Models\AuditRunItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditBatchIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_step3_failed_job_does_not_auto_retry_recoverable_batch_shape_errors(): void
    {
        Queue::fake();

        $run = $this->makeRun();
        $items = $this->makeItems($run, 2, 'analyzing', 'url_only_batch_step3_running');

        $job = new ProcessAuditRunStep3BatchJob($run->id, $items->pluck('id')->all());
        $job->failed(new \RuntimeException('Bước 3.5 JSON thiếu dòng kết quả: cần 2, nhận 1.'));

        $items->each->refresh();
        $run->refresh();

        foreach ($items as $item) {
            $this->assertSame('failed', $item->status);
            $this->assertStringContainsString('[Bước 3: audit onpage]', (string) $item->error_message);
            $this->assertNotNull($item->completed_at);
        }

        Queue::assertNotPushed(ProcessAuditRunStep3BatchJob::class);
    }

    public function test_step3_failed_job_only_fails_its_own_chunk_without_dispatching_other_chunks(): void
    {
        Queue::fake();

        $run = $this->makeRun(totalUrls: 4);
        $failedChunk = $this->makeItems($run, 2, 'analyzing', 'url_only_batch_step3_running', 1);
        $pendingChunk = $this->makeItems($run, 2, 'analyzing', 'url_only_batch_step2_done', 3);

        $job = new ProcessAuditRunStep3BatchJob($run->id, $failedChunk->pluck('id')->all());
        $job->failed(new \RuntimeException('Gemini formatter timeout.'));

        $failedChunk->each->refresh();
        $pendingChunk->each->refresh();
        $run->refresh();

        foreach ($failedChunk as $item) {
            $this->assertSame('failed', $item->status);
            $this->assertStringContainsString('[Bước 3: audit onpage]', (string) $item->error_message);
            $this->assertNotNull($item->completed_at);
        }

        foreach ($pendingChunk as $item) {
            $this->assertSame('analyzing', $item->status);
            $this->assertSame('url_only_batch_step2_done', $item->extraction_source);
            $this->assertNull($item->error_message);
        }

        Queue::assertNotPushed(ProcessAuditRunStep3BatchJob::class);
    }

    private function makeRun(int $totalUrls = 2): AuditRun
    {
        return AuditRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => 'website-test',
            'website_name' => 'Website test',
            'website_url' => 'https://example.com',
            'user_uid' => 'user-test',
            'user_email' => 'test@example.com',
            'status' => 'processing',
            'target_urls' => array_map(fn (int $index): string => "https://example.com/post-{$index}", range(1, $totalUrls)),
            'categories' => [],
            'checklist_text' => null,
            'ai_provider' => 'gemini_deep_research',
            'ai_model' => 'deep-research-pro-preview-12-2025',
            'step2_ai_provider' => 'gemini',
            'step2_ai_model' => 'gemini-2.5-flash',
            'step3_ai_provider' => 'gemini_deep_research',
            'step3_ai_model' => 'deep-research-pro-preview-12-2025',
            'step2_formatter_provider' => 'gemini',
            'step2_formatter_model' => 'gemini-2.5-flash',
            'step3_formatter_provider' => 'gemini',
            'step3_formatter_model' => 'gemini-2.5-flash',
            'total_urls' => $totalUrls,
            'processed_urls' => 0,
            'completed_urls' => 0,
            'failed_urls' => 0,
            'started_at' => now(),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, AuditRunItem>
     */
    private function makeItems(AuditRun $run, int $count, string $status, string $source, int $startPosition = 1)
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
                ]);
            });
    }
}
