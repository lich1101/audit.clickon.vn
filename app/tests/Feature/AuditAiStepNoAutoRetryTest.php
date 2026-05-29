<?php

namespace Tests\Feature;

use App\Jobs\ProcessAuditRunStep3BatchJob;
use App\Models\AuditRun;
use App\Models\AuditRunItem;
use App\Services\AuditRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditAiStepNoAutoRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_step3_job_failure_does_not_redispatch_ai_batches(): void
    {
        Queue::fake();

        $run = AuditRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => 'website-test',
            'website_name' => 'Website test',
            'website_url' => 'https://example.com',
            'user_uid' => 'user-test',
            'user_email' => 'test@example.com',
            'status' => 'processing',
            'workflow' => AuditRun::WORKFLOW_STANDARD,
            'start_from_step' => 3,
            'target_urls' => ['https://example.com/a', 'https://example.com/b'],
            'categories' => [],
            'category_contexts' => [],
            'checklist_text' => 'Checklist',
            'ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-pro',
            'step3_ai_provider' => 'gemini',
            'step3_ai_model' => 'gemini-2.5-pro',
            'total_urls' => 2,
            'processed_urls' => 0,
            'completed_urls' => 0,
            'failed_urls' => 0,
            'started_at' => now(),
        ]);

        $items = collect(['https://example.com/a', 'https://example.com/b'])->map(function (string $url, int $index) use ($run) {
            return AuditRunItem::query()->create([
                'public_id' => (string) Str::ulid(),
                'audit_run_id' => $run->id,
                'position' => $index + 1,
                'target_url' => $url,
                'status' => 'analyzing',
                'extraction_source' => 'url_only_batch_step3_running',
                'primary_keyword' => 'kw',
                'category_name' => 'cat',
                'category_url' => 'https://example.com/cat',
            ]);
        });

        $job = new ProcessAuditRunStep3BatchJob($run->id, $items->pluck('id')->all());
        $job->failed(new \RuntimeException('Gemini API lỗi HTTP 503'));

        Queue::assertNotPushed(ProcessAuditRunStep3BatchJob::class);

        $items->each->refresh();
        $this->assertSame('failed', $items[0]->status);
        $this->assertStringContainsString('[Bước 3: audit onpage]', (string) $items[0]->error_message);
    }

    public function test_failed_step3_items_are_not_auto_redispatched_by_recovery(): void
    {
        Queue::fake();

        $run = AuditRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => 'website-test',
            'website_name' => 'Website test',
            'website_url' => 'https://example.com',
            'user_uid' => 'user-test',
            'user_email' => 'test@example.com',
            'status' => 'partial',
            'workflow' => AuditRun::WORKFLOW_STANDARD,
            'start_from_step' => 3,
            'target_urls' => ['https://example.com/failed'],
            'categories' => [],
            'category_contexts' => [],
            'checklist_text' => 'Checklist',
            'step3_ai_provider' => 'gemini',
            'step3_ai_model' => 'gemini-2.5-pro',
            'total_urls' => 1,
            'processed_urls' => 1,
            'completed_urls' => 0,
            'failed_urls' => 1,
            'started_at' => now(),
        ]);

        AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => 1,
            'target_url' => 'https://example.com/failed',
            'status' => 'failed',
            'extraction_source' => 'url_only_batch_step3_running',
            'error_message' => '[Bước 3: audit onpage] Gemini API lỗi HTTP 503',
            'updated_at' => now()->subMinutes(10),
        ]);

        $changed = app(AuditRunService::class)->recoverStep3DbApplyFromSavedParsed($run);

        $this->assertFalse($changed);
        Queue::assertNotPushed(ProcessAuditRunStep3BatchJob::class);
    }
}
