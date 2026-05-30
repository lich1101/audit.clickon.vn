<?php

namespace Tests\Feature;

use App\Models\AuditRun;
use App\Models\AuditRunItem;
use App\Services\AuditRunService;
use App\Services\SeoAiAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AuditStep3WatchdogTest extends TestCase
{
    use RefreshDatabase;

    public function test_watchdog_recovers_completed_stale_gemini_deep_research_batch(): void
    {
        Queue::fake();

        $run = $this->makeRun([
            'step3_ai_provider' => 'gemini_deep_research',
            'step3_ai_model' => 'deep-research-preview-04-2026',
            'total_urls' => 1,
        ]);
        $item = $this->makeStep3Item($run, now()->subMinutes(10));
        $stepKey = 'batch_onpage_audit_001_001';
        $run->forceFill([
            'ai_step_responses' => [
                $stepKey => [
                    'step' => $stepKey,
                    'provider' => 'gemini_deep_research',
                    'model' => 'deep-research-preview-04-2026',
                    'interactionId' => 'interaction-123',
                    'lastPollAt' => now()->subMinutes(10)->toIso8601String(),
                ],
            ],
        ])->save();

        $seoAi = Mockery::mock(SeoAiAuditService::class);
        $seoAi->shouldReceive('inspectGeminiDeepResearchInteraction')
            ->once()
            ->with('interaction-123', $run->id, $stepKey, 'deep-research-preview-04-2026')
            ->andReturn([
                'status' => 'completed',
                'interactionId' => 'interaction-123',
                'rawText' => '{"items":[]}',
                'usage' => [],
                'errorMessage' => null,
            ]);
        $seoAi->shouldReceive('resumeBatchOnpageUrlOnlyFromRaw')
            ->once()
            ->andReturn([
                'items' => [[
                    'targetUrl' => $item->target_url,
                    'primaryKeyword' => 'thu mua dây cáp điện phế liệu',
                    'categoryName' => 'Phế liệu đồng',
                    'categoryUrl' => 'https://example.com/phe-lieu-dong',
                    'categoryMatchReason' => 'Khớp theo dữ liệu bước 2.',
                    'auditScore' => 78,
                    'auditFindings' => ['Điểm kỹ thuật SEO: 18/24', 'Điểm nội dung: 5/6'],
                    'auditRecommendations' => ['Bổ sung thêm hình ảnh thực tế'],
                    'contentRevisionDirection' => 'Audit Content. Cần hoàn thiện thêm một số điểm onpage.',
                ]],
                'promptSnapshot' => null,
                'formatterPromptSnapshot' => null,
                'usage' => [],
                'usageEvents' => [],
            ]);
        $this->app->instance(SeoAiAuditService::class, $seoAi);

        app(AuditRunService::class)->recoverStaleGeminiDeepResearchStep3Batches($run);

        $item->refresh();
        $run->refresh();

        $this->assertSame('completed', $item->status);
        $this->assertSame('url_only_batch', $item->extraction_source);
        $this->assertSame(78, $item->audit_score);
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->processed_urls);
        $this->assertSame(1, $run->completed_urls);
    }

    public function test_watchdog_fails_stale_batch_without_interaction_id(): void
    {
        Queue::fake();

        $run = $this->makeRun([
            'step3_ai_provider' => 'gemini_deep_research',
            'step3_ai_model' => 'deep-research-preview-04-2026',
            'total_urls' => 1,
        ]);
        $item = $this->makeStep3Item($run, now()->subMinutes(10));
        $stepKey = 'batch_onpage_audit_001_001';
        $run->forceFill([
            'ai_step_responses' => [
                $stepKey => [
                    'step' => $stepKey,
                    'provider' => 'gemini_deep_research',
                    'model' => 'deep-research-preview-04-2026',
                    'lastPollAt' => now()->subMinutes(10)->toIso8601String(),
                ],
            ],
        ])->save();

        app(AuditRunService::class)->recoverStaleGeminiDeepResearchStep3Batches($run);

        $item->refresh();
        $run->refresh();

        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('không có interaction id', (string) $item->error_message);
        $this->assertSame(1, $run->failed_urls);
    }

    public function test_recover_stale_runs_command_only_scans_eligible_runs(): void
    {
        Queue::fake();

        $recoverableRun = $this->makeRun([
            'step3_ai_provider' => 'gemini_deep_research',
            'step3_ai_model' => 'deep-research-preview-04-2026',
            'total_urls' => 1,
        ]);
        $recoverableItem = $this->makeStep3Item($recoverableRun, now()->subMinutes(10));
        $stepKey = 'batch_onpage_audit_001_001';
        $recoverableRun->forceFill([
            'ai_step_responses' => [
                $stepKey => [
                    'step' => $stepKey,
                    'provider' => 'gemini_deep_research',
                    'model' => 'deep-research-preview-04-2026',
                    'interactionId' => 'interaction-456',
                    'lastPollAt' => now()->subMinutes(10)->toIso8601String(),
                ],
            ],
        ])->save();

        $ignoredRun = $this->makeRun([
            'public_id' => (string) Str::ulid(),
            'step3_ai_provider' => 'openai',
            'step3_ai_model' => 'gpt-4.1',
            'total_urls' => 1,
        ]);
        $this->makeStep3Item($ignoredRun, now()->subSeconds(30));

        $seoAi = Mockery::mock(SeoAiAuditService::class);
        $seoAi->shouldReceive('inspectGeminiDeepResearchInteraction')
            ->once()
            ->with('interaction-456', $recoverableRun->id, $stepKey, 'deep-research-preview-04-2026')
            ->andReturn([
                'status' => 'completed',
                'interactionId' => 'interaction-456',
                'rawText' => '{"items":[]}',
                'usage' => [],
                'errorMessage' => null,
            ]);
        $seoAi->shouldReceive('resumeBatchOnpageUrlOnlyFromRaw')
            ->once()
            ->andReturn([
                'items' => [[
                    'targetUrl' => $recoverableItem->target_url,
                    'primaryKeyword' => 'thu mua dây cáp điện phế liệu',
                    'categoryName' => 'Phế liệu đồng',
                    'categoryUrl' => 'https://example.com/phe-lieu-dong',
                    'categoryMatchReason' => 'Khớp theo dữ liệu bước 2.',
                    'auditScore' => 81,
                    'auditFindings' => ['Điểm kỹ thuật SEO: 19/24', 'Điểm nội dung: 5/6'],
                    'auditRecommendations' => ['Bổ sung hình ảnh thực tế'],
                    'contentRevisionDirection' => 'Audit Content. Cần hoàn thiện thêm visual và internal link.',
                ]],
                'promptSnapshot' => null,
                'formatterPromptSnapshot' => null,
                'usage' => [],
                'usageEvents' => [],
            ]);
        $this->app->instance(SeoAiAuditService::class, $seoAi);

        $this->assertSame(0, Artisan::call('audit:recover-stale-runs', ['--json' => true]));

        $payload = json_decode(trim(Artisan::output()), true);

        $recoverableItem->refresh();
        $ignoredRun->refresh();

        $this->assertIsArray($payload);
        $this->assertSame(2, $payload['scanned'] ?? null);
        $this->assertSame(1, $payload['changed'] ?? null);
        $this->assertSame(1, $payload['recovered'] ?? null);
        $this->assertSame(0, $payload['failedMarked'] ?? null);
        $this->assertSame('completed', $recoverableItem->status);
        $this->assertSame('processing', $ignoredRun->status);
        $this->assertSame(0, $ignoredRun->processed_urls);
    }

    public function test_recover_stale_runs_command_does_not_count_failed_release_as_recovered(): void
    {
        Queue::fake();

        $run = $this->makeRun([
            'step3_ai_provider' => 'gemini_deep_research',
            'step3_ai_model' => 'deep-research-preview-04-2026',
            'total_urls' => 1,
        ]);
        $this->makeStep3Item($run, now()->subMinutes(10));
        $stepKey = 'batch_onpage_audit_001_001';
        $run->forceFill([
            'ai_step_responses' => [
                $stepKey => [
                    'step' => $stepKey,
                    'provider' => 'gemini_deep_research',
                    'model' => 'deep-research-preview-04-2026',
                    'lastPollAt' => now()->subMinutes(10)->toIso8601String(),
                ],
            ],
        ])->save();

        $this->assertSame(0, Artisan::call('audit:recover-stale-runs', ['--json' => true]));

        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['scanned'] ?? null);
        $this->assertSame(1, $payload['changed'] ?? null);
        $this->assertSame(0, $payload['recovered'] ?? null);
        $this->assertSame(1, $payload['failedMarked'] ?? null);
    }

    public function test_recover_run_command_accepts_ui_short_suffix(): void
    {
        Queue::fake();

        $run = $this->makeRun([
            'step3_ai_provider' => 'gemini_deep_research',
            'step3_ai_model' => 'deep-research-preview-04-2026',
            'total_urls' => 1,
        ]);
        $item = $this->makeStep3Item($run, now()->subMinutes(10));
        $stepKey = 'batch_onpage_audit_001_001';
        $run->forceFill([
            'ai_step_responses' => [
                $stepKey => [
                    'step' => $stepKey,
                    'provider' => 'gemini_deep_research',
                    'model' => 'deep-research-preview-04-2026',
                    'interactionId' => 'interaction-789',
                    'lastPollAt' => now()->subMinutes(10)->toIso8601String(),
                ],
            ],
        ])->save();

        $seoAi = Mockery::mock(SeoAiAuditService::class);
        $seoAi->shouldReceive('inspectGeminiDeepResearchInteraction')
            ->once()
            ->with('interaction-789', $run->id, $stepKey, 'deep-research-preview-04-2026')
            ->andReturn([
                'status' => 'completed',
                'interactionId' => 'interaction-789',
                'rawText' => '{"items":[]}',
                'usage' => [],
                'errorMessage' => null,
            ]);
        $seoAi->shouldReceive('resumeBatchOnpageUrlOnlyFromRaw')
            ->once()
            ->andReturn([
                'items' => [[
                    'targetUrl' => $item->target_url,
                    'primaryKeyword' => 'thu mua dây cáp điện phế liệu',
                    'categoryName' => 'Phế liệu đồng',
                    'categoryUrl' => 'https://example.com/phe-lieu-dong',
                    'categoryMatchReason' => 'Khớp theo dữ liệu bước 2.',
                    'auditScore' => 80,
                    'auditFindings' => ['Điểm kỹ thuật SEO: 19/24', 'Điểm nội dung: 5/6'],
                    'auditRecommendations' => ['Bổ sung internal link'],
                    'contentRevisionDirection' => 'Audit Content. Cần tối ưu thêm internal link và CTA.',
                ]],
                'promptSnapshot' => null,
                'formatterPromptSnapshot' => null,
                'usage' => [],
                'usageEvents' => [],
            ]);
        $this->app->instance(SeoAiAuditService::class, $seoAi);

        $shortSuffix = substr((string) $run->public_id, -8);

        $this->assertSame(0, Artisan::call('audit:recover-run', [
            'publicId' => $shortSuffix,
            '--json' => true,
        ]));

        $payload = json_decode(trim(Artisan::output()), true);

        $item->refresh();

        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame((string) $run->public_id, $payload['publicId'] ?? null);
        $this->assertSame('completed', $item->status);
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
            'start_from_step' => 3,
            'target_urls' => ['https://example.com/post-1'],
            'categories' => [
                ['name' => 'Phế liệu đồng', 'url' => 'https://example.com/phe-lieu-dong'],
            ],
            'category_contexts' => [],
            'checklist_text' => 'Checklist test',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4.1',
            'step2_ai_provider' => 'openai',
            'step2_ai_model' => 'gpt-4.1',
            'step3_ai_provider' => 'gemini_deep_research',
            'step3_ai_model' => 'deep-research-preview-04-2026',
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

    private function makeStep3Item(AuditRun $run, \Illuminate\Support\Carbon $updatedAt): AuditRunItem
    {
        $item = AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => 1,
            'target_url' => 'https://example.com/post-1',
            'status' => 'analyzing',
            'extraction_source' => 'url_only_batch_step3_running',
            'primary_keyword' => 'thu mua dây cáp điện phế liệu',
            'category_name' => 'Phế liệu đồng',
            'category_url' => 'https://example.com/phe-lieu-dong',
            'category_match_reason' => 'Khớp seed.',
        ]);

        AuditRunItem::query()
            ->whereKey($item->id)
            ->update([
            'created_at' => now(),
            'updated_at' => $updatedAt,
        ]);

        return $item->fresh();
    }
}
