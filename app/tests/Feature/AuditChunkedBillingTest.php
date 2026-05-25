<?php

namespace Tests\Feature;

use App\Services\TokenBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditChunkedBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_chunked_run_estimate_no_longer_reserves_fixed_call_credits(): void
    {
        $service = app(TokenBillingService::class);

        $this->assertSame(
            0,
            $service->estimateMinimumCreditsForChunkedRun(
                provider: 'openai',
                model: 'gpt-5.5',
                totalUrls: 60,
                step2BatchSize: 60,
                step3BatchSize: 30,
            )
        );

        $this->assertSame(
            0,
            $service->estimateMinimumCreditsForChunkedRun(
                provider: 'openai',
                model: 'gpt-5.5',
                totalUrls: 61,
                step2BatchSize: 60,
                step3BatchSize: 30,
            )
        );
    }

    public function test_actual_credit_charge_is_calculated_from_reported_token_usage(): void
    {
        $service = app(TokenBillingService::class);

        $this->assertSame(20, $service->calculateCredits('openai', 'gpt-5.5', 1000, 1000));
        $this->assertSame(1, $service->calculateCredits('openai', 'gpt-5.5', 1, 1));
        $this->assertSame(0, $service->calculateCredits('gemini_deep_research', 'deep-research-pro-preview-12-2025', 1000, 1000));
        $this->assertSame(0, $service->calculateCredits('gemini_deep_research', 'deep-research-preview-04-2026', 1000, 1000));
    }
}
