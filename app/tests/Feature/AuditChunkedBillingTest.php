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
    }

    public function test_usd_charge_uses_provider_reported_cost_when_available(): void
    {
        $service = app(TokenBillingService::class);

        $result = $service->calculateUsdForUsage([
            'provider' => 'perplexity',
            'model' => 'sonar-deep-research',
            'input_tokens' => 1000,
            'output_tokens' => 1000,
            'provider_reported_cost_usd' => 0.321654,
        ]);

        $this->assertSame('provider_reported', $result['source']);
        $this->assertEquals(0.321654, $result['amount']);
        $this->assertTrue($result['isExact']);
    }

    public function test_usd_charge_uses_token_pricing_for_gemini_pro(): void
    {
        $service = app(TokenBillingService::class);

        $result = $service->calculateUsdForUsage([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-pro',
            'input_tokens' => 1_000_000,
            'output_tokens' => 0,
        ]);

        $this->assertSame('estimated_tokens', $result['source']);
        $this->assertEquals(1.25, $result['amount']);
    }

    public function test_legacy_credit_calculation_remains_available_for_reference(): void
    {
        $service = app(TokenBillingService::class);

        $this->assertSame(20, $service->calculateCredits('openai', 'gpt-5.5', 1000, 1000));
    }
}
