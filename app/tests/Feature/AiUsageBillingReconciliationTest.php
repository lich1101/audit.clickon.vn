<?php

namespace Tests\Feature;

use App\Models\AiUsageEvent;
use App\Models\AppUser;
use App\Models\AuditRun;
use App\Models\AuditRunItem;
use App\Models\CreditTransaction;
use App\Models\Website;
use App\Services\AiUsageBillingReconciliationService;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiUsageBillingReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_and_backfill_undercharged_gemini_long_context_event(): void
    {
        config()->set('services.audit.billing_markup', 1.0);

        $user = AppUser::query()->create([
            'firebase_uid' => 'user-reconcile-1',
            'email' => 'reconcile@example.com',
            'display_name' => 'Reconcile User',
            'role' => 'user',
            'credits' => 10000,
            'captcha_credits' => 0,
            'balance_usd' => 100.0,
        ]);

        $website = Website::query()->create([
            'id' => (string) Str::ulid(),
            'user_uid' => $user->firebase_uid,
            'name' => 'Example Website',
            'url' => 'https://example.com',
        ]);

        $run = AuditRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => (string) $website->id,
            'website_name' => $website->name,
            'website_url' => $website->url,
            'user_uid' => $user->firebase_uid,
            'user_email' => $user->email,
            'status' => 'completed',
            'workflow' => AuditRun::WORKFLOW_STANDARD,
            'pipeline_mode' => AuditRun::PIPELINE_FAST,
            'target_urls' => ['https://example.com/article'],
            'categories' => [],
            'checklist_text' => 'Checklist',
            'total_urls' => 1,
            'processed_urls' => 1,
            'completed_urls' => 1,
            'failed_urls' => 0,
        ]);

        $item = AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => 1,
            'target_url' => 'https://example.com/article',
            'status' => 'completed',
        ]);

        app(CreditService::class)->mutateUsd(
            firebaseUid: $user->firebase_uid,
            type: 'subtract',
            amountUsd: 0.525000,
            reason: 'Old AI billing before long-context tier fix',
            source: 'audit',
            referenceType: 'audit_run_item',
            referenceId: (string) $item->public_id,
        );

        $event = AiUsageEvent::query()->create([
            'audit_run_item_id' => $item->id,
            'step' => 'batch_fast_audit',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-pro',
            'input_tokens' => 300000,
            'output_tokens' => 10000,
            'total_tokens' => 315000,
            'citation_tokens' => 0,
            'reasoning_tokens' => 5000,
            'search_queries' => 0,
            'provider_reported_cost_usd' => null,
            'credits_charged' => 53,
            'usd_charged' => 0.525000,
        ]);

        $service = app(AiUsageBillingReconciliationService::class);

        $report = $service->report([
            'status' => 'undercharged',
            'provider' => 'gemini',
            'limit' => 50,
        ]);

        $this->assertSame(1, $report['summary']['underchargedEventCount']);
        $this->assertSame(1, $report['summary']['affectedRunCount']);
        $this->assertCount(1, $report['runs']);
        $this->assertCount(1, $report['events']);
        $this->assertEqualsWithDelta(0.450000, (float) $report['events'][0]['usdDelta'], 0.000001);
        $this->assertSame(45, (int) $report['events'][0]['creditDelta']);

        $backfill = $service->backfill([
            'provider' => 'gemini',
            'runPublicIds' => [(string) $run->public_id],
            'limit' => 50,
        ]);

        $this->assertSame(1, $backfill['summary']['candidateEventCount']);
        $this->assertSame(1, $backfill['summary']['appliedEventCount']);
        $this->assertEqualsWithDelta(0.450000, (float) $backfill['summary']['appliedUsdDelta'], 0.000001);
        $this->assertSame(45, (int) $backfill['summary']['appliedCreditDelta']);

        $event->refresh();
        $user->refresh();

        $this->assertEqualsWithDelta(0.975000, (float) $event->usd_charged, 0.000001);
        $this->assertSame(98, (int) $event->credits_charged);
        $this->assertEqualsWithDelta(99.025000, (float) $user->balance_usd, 0.000001);
        $this->assertSame(9902, (int) $user->credits);

        $adjustment = CreditTransaction::query()
            ->where('source', 'audit_reconcile')
            ->where('reference_type', 'ai_usage_event')
            ->where('reference_id', (string) $event->id)
            ->first();

        $this->assertNotNull($adjustment);
        $this->assertEqualsWithDelta(0.450000, (float) $adjustment->amount_usd, 0.000001);
        $this->assertSame(45, (int) $adjustment->amount);
    }
}
