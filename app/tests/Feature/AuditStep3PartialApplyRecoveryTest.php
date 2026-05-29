<?php

namespace Tests\Feature;

use App\Models\AuditRun;
use App\Models\AuditRunItem;
use App\Services\AuditRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditStep3PartialApplyRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovers_partial_step3_apply_from_saved_parsed_batch(): void
    {
        Queue::fake();

        $run = $this->makeRun([
            'step3_ai_provider' => 'gemini',
            'step3_ai_model' => 'gemini-2.5-pro',
            'total_urls' => 3,
        ]);

        $completedItem = $this->makeStep3Item($run, 1, 'completed', 'https://example.com/done');
        $pendingOne = $this->makeStep3Item($run, 2, 'analyzing', 'https://example.com/pending-1');
        $pendingTwo = $this->makeStep3Item($run, 3, 'analyzing', 'https://example.com/pending-2');

        $stepKey = 'batch_onpage_audit_001_003';
        $run->forceFill([
            'ai_step_responses' => [
                $stepKey => [
                    'step' => $stepKey,
                    'status' => 'parsed',
                    'provider' => 'gemini',
                    'model' => 'gemini-2.5-pro',
                    'parsed' => [
                        'items' => [
                            [
                                'targetUrl' => $completedItem->target_url,
                                'auditScore' => 70,
                                'auditFindings' => ['ok'],
                                'auditRecommendations' => ['keep'],
                                'contentRevisionDirection' => 'Audit Content.',
                            ],
                            [
                                'targetUrl' => $pendingOne->target_url,
                                'auditScore' => 55,
                                'auditFindings' => ['needs title'],
                                'auditRecommendations' => ['fix title'],
                                'contentRevisionDirection' => 'Audit Content.',
                            ],
                            [
                                'targetUrl' => $pendingTwo->target_url,
                                'auditScore' => 61,
                                'auditFindings' => ['needs links'],
                                'auditRecommendations' => ['add links'],
                                'contentRevisionDirection' => 'Audit Content.',
                            ],
                        ],
                    ],
                ],
            ],
        ])->save();

        $changed = app(AuditRunService::class)->recoverStep3DbApplyFromSavedParsed($run);

        $pendingOne->refresh();
        $pendingTwo->refresh();
        $run->refresh();

        $this->assertTrue($changed);
        $this->assertSame('completed', $pendingOne->status);
        $this->assertSame('completed', $pendingTwo->status);
        $this->assertSame(55, $pendingOne->audit_score);
        $this->assertSame(61, $pendingTwo->audit_score);
        $this->assertSame('url_only_batch', $pendingOne->extraction_source);
    }

    public function test_recover_stale_runs_includes_standard_gemini_runs(): void
    {
        Queue::fake();

        $recoverableRun = $this->makeRun([
            'step3_ai_provider' => 'gemini',
            'step3_ai_model' => 'gemini-2.5-pro',
            'total_urls' => 1,
        ]);
        $item = $this->makeStep3Item($recoverableRun, 1, 'analyzing', 'https://example.com/post-1');
        $stepKey = 'batch_onpage_audit_001_001';
        $recoverableRun->forceFill([
            'ai_step_responses' => [
                $stepKey => [
                    'step' => $stepKey,
                    'status' => 'parsed',
                    'provider' => 'gemini',
                    'model' => 'gemini-2.5-pro',
                    'parsed' => [
                        'items' => [[
                            'targetUrl' => $item->target_url,
                            'auditScore' => 72,
                            'auditFindings' => ['ok'],
                            'auditRecommendations' => ['keep'],
                            'contentRevisionDirection' => 'Audit Content.',
                        ]],
                    ],
                ],
            ],
        ])->save();

        $ignoredRun = $this->makeRun([
            'public_id' => (string) Str::ulid(),
            'step3_ai_provider' => 'gemini',
            'step3_ai_model' => 'gemini-2.5-pro',
            'total_urls' => 1,
        ]);
        $this->makeStep3Item($ignoredRun, 1, 'analyzing', 'https://example.com/post-2', now()->subSeconds(10));

        $this->assertSame(0, Artisan::call('audit:recover-stale-runs', ['--json' => true]));

        $payload = json_decode(trim(Artisan::output()), true);
        $item->refresh();
        $ignoredRun->refresh();

        $this->assertSame(2, $payload['scanned'] ?? null);
        $this->assertSame(1, $payload['changed'] ?? null);
        $this->assertSame(1, $payload['recovered'] ?? null);
        $this->assertSame('completed', $item->status);
        $this->assertSame('processing', $ignoredRun->status);
        $this->assertSame(0, $ignoredRun->processed_urls);
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
            'step3_ai_provider' => 'gemini',
            'step3_ai_model' => 'gemini-2.5-pro',
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

    private function makeStep3Item(
        AuditRun $run,
        int $position,
        string $status,
        string $targetUrl,
        ?\Illuminate\Support\Carbon $updatedAt = null,
    ): AuditRunItem {
        $item = AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => $position,
            'target_url' => $targetUrl,
            'status' => $status,
            'extraction_source' => $status === 'completed' ? 'url_only_batch' : 'url_only_batch_step3_running',
            'primary_keyword' => 'keyword test',
            'category_name' => 'Phế liệu đồng',
            'category_url' => 'https://example.com/phe-lieu-dong',
            'updated_at' => $updatedAt ?? now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
        ]);

        if ($updatedAt) {
            $item->forceFill(['updated_at' => $updatedAt])->save();
        }

        return $item;
    }
}
