<?php

namespace Tests\Feature;

use App\Services\AiModelCatalogService;
use App\Services\AuditConfigurationCheckService;
use App\Services\AuditSettingsService;
use App\Services\TokenBillingService;
use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_settings_store_step_specific_providers_and_models(): void
    {
        $settings = app(AuditSettingsService::class)->updateAuditSettings([
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-5.5',
            'step2AiProvider' => 'gemini',
            'step2AiModel' => 'gemini-2.5-flash',
            'step3AiProvider' => 'gemini_deep_research',
            'step3AiModel' => 'deep-research-pro-preview-12-2025',
            'step2FormatterProvider' => 'gemini',
            'step2FormatterModel' => 'gemini-2.5-flash',
            'step3FormatterProvider' => 'openai',
            'step3FormatterModel' => 'gpt-5.5',
            'step3FlowMode' => 'audit_deep_research',
            'maxParallelItems' => 3,
            'step2BatchSize' => 60,
            'step3BatchSize' => 30,
            'deepResearchBatchSize' => 5,
            'deepResearchResearchProvider' => 'perplexity',
            'deepResearchResearchModel' => 'sonar-deep-research',
            'deepResearchReasoningProvider' => 'openai',
            'deepResearchReasoningModel' => 'gpt-5.5',
            'deepResearchFormatterProvider' => 'gemini',
            'deepResearchFormatterModel' => 'gemini-2.5-flash',
        ]);

        $this->assertSame('gemini', $settings['step2AiProvider']);
        $this->assertSame('gemini-2.5-flash', $settings['step2AiModel']);
        $this->assertSame('gemini_deep_research', $settings['step3AiProvider']);
        $this->assertSame('deep-research-pro-preview-12-2025', $settings['step3AiModel']);
        $this->assertSame('audit_deep_research', $settings['step3FlowMode']);
        $this->assertSame(5, $settings['deepResearchBatchSize']);
        $this->assertSame('perplexity', $settings['deepResearchResearchProvider']);
        $this->assertSame('sonar-deep-research', $settings['deepResearchResearchModel']);
        $this->assertSame('openai', $settings['deepResearchReasoningProvider']);
        $this->assertSame('gpt-5.5', $settings['deepResearchReasoningModel']);
        $this->assertSame('gemini', $settings['deepResearchFormatterProvider']);
        $this->assertSame('gemini-2.5-flash', $settings['deepResearchFormatterModel']);
    }

    public function test_deep_research_catalog_includes_current_and_legacy_agents(): void
    {
        $catalog = app(AiModelCatalogService::class)->listForProvider('gemini_deep_research');
        $ids = collect($catalog['models'])->pluck('id')->all();

        $this->assertContains('deep-research-pro-preview-12-2025', $ids);
        $this->assertContains('deep-research-preview-04-2026', $ids);
    }

    public function test_model_pricing_list_and_sync_include_usd_fields(): void
    {
        $billing = app(TokenBillingService::class);

        $billing->syncPricing([
            [
                'provider' => 'openai',
                'model' => 'gpt-5.5',
                'label' => 'GPT-5.5',
                'creditsPer1kInput' => 5,
                'creditsPer1kOutput' => 15,
                'usdPer1MInput' => 5,
                'usdPer1MOutput' => 30,
                'usdPer1MReasoning' => null,
                'usdPer1MCitation' => null,
                'usdPer1kSearchQueries' => null,
                'minCreditsPerCall' => 3,
            ],
        ]);

        $row = collect($billing->listPricing())->firstWhere(fn (array $pricing): bool => $pricing['provider'] === 'openai' && $pricing['model'] === 'gpt-5.5');

        $this->assertNotNull($row);
        $this->assertSame(5.0, $row['usdPer1MInput']);
        $this->assertSame(30.0, $row['usdPer1MOutput']);
        $this->assertNull($row['usdPer1MReasoning']);
    }

    public function test_configuration_check_reports_missing_openai_key_in_standard_mode(): void
    {
        Config::set('services.openai.api_key', '');
        Config::set('services.gemini.api_key', 'gemini-test-key');

        app(AuditSettingsService::class)->updateAuditSettings([
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-5.5',
            'step2AiProvider' => 'openai',
            'step2AiModel' => 'gpt-5.5',
            'step3AiProvider' => 'openai',
            'step3AiModel' => 'gpt-5.5',
            'step2FormatterProvider' => 'gemini',
            'step2FormatterModel' => 'gemini-2.5-flash',
            'step3FormatterProvider' => 'openai',
            'step3FormatterModel' => 'gpt-5.5',
            'step3FlowMode' => 'standard',
            'maxParallelItems' => 3,
            'step2BatchSize' => 60,
            'step3BatchSize' => 30,
            'deepResearchBatchSize' => 5,
            'deepResearchResearchProvider' => 'perplexity',
            'deepResearchResearchModel' => 'sonar-deep-research',
            'deepResearchReasoningProvider' => 'openai',
            'deepResearchReasoningModel' => 'gpt-5.5',
            'deepResearchFormatterProvider' => 'openai',
            'deepResearchFormatterModel' => 'gpt-5.5',
        ]);

        $report = app(AuditConfigurationCheckService::class)->check();

        $this->assertFalse($report['ready']);
        $this->assertSame('standard', $report['step3FlowMode']);
        $this->assertGreaterThan(0, $report['summary']['error']);
        $this->assertStringContainsString(
            'OPENAI_API_KEY',
            collect($report['groups'])->flatMap(fn (array $group) => $group['items'])->pluck('message')->implode("\n")
        );
    }

    public function test_configuration_check_passes_for_deep_research_mode_with_required_keys(): void
    {
        Config::set('services.openai.api_key', 'openai-test-key');
        Config::set('services.perplexity.api_key', 'perplexity-test-key');
        Config::set('services.gemini.api_key', 'gemini-test-key');
        Config::set('services.audit.deep_research_research_use_async', true);

        app(AuditSettingsService::class)->updateAuditSettings([
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-5.5',
            'step2AiProvider' => 'openai',
            'step2AiModel' => 'gpt-5.5',
            'step3AiProvider' => 'openai',
            'step3AiModel' => 'gpt-5.5',
            'step2FormatterProvider' => 'gemini',
            'step2FormatterModel' => 'gemini-2.5-flash',
            'step3FormatterProvider' => 'openai',
            'step3FormatterModel' => 'gpt-5.5',
            'step3FlowMode' => 'audit_deep_research',
            'maxParallelItems' => 3,
            'step2BatchSize' => 60,
            'step3BatchSize' => 30,
            'deepResearchBatchSize' => 20,
            'deepResearchResearchProvider' => 'perplexity',
            'deepResearchResearchModel' => 'sonar-deep-research',
            'deepResearchReasoningProvider' => 'openai',
            'deepResearchReasoningModel' => 'gpt-5.5',
            'deepResearchFormatterProvider' => 'openai',
            'deepResearchFormatterModel' => 'gpt-5.5',
        ]);

        $report = app(AuditConfigurationCheckService::class)->check();

        $this->assertTrue($report['ready']);
        $this->assertSame('audit_deep_research', $report['step3FlowMode']);
        $this->assertSame(0, $report['summary']['error']);
    }

    public function test_configuration_check_accepts_gemini_deep_research_and_gemini_reasoning(): void
    {
        Config::set('services.openai.api_key', 'openai-test-key');
        Config::set('services.perplexity.api_key', 'perplexity-test-key');
        Config::set('services.gemini.api_key', 'gemini-test-key');

        app(AuditSettingsService::class)->updateAuditSettings([
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-5.5',
            'step2AiProvider' => 'openai',
            'step2AiModel' => 'gpt-5.5',
            'step3AiProvider' => 'openai',
            'step3AiModel' => 'gpt-5.5',
            'step2FormatterProvider' => 'gemini',
            'step2FormatterModel' => 'gemini-2.5-flash',
            'step3FormatterProvider' => 'openai',
            'step3FormatterModel' => 'gpt-5.5',
            'step3FlowMode' => 'audit_deep_research',
            'maxParallelItems' => 3,
            'step2BatchSize' => 60,
            'step3BatchSize' => 30,
            'deepResearchBatchSize' => 10,
            'deepResearchResearchProvider' => 'gemini_deep_research',
            'deepResearchResearchModel' => 'deep-research-preview-04-2026',
            'deepResearchReasoningProvider' => 'gemini',
            'deepResearchReasoningModel' => 'gemini-2.5-pro',
            'deepResearchFormatterProvider' => 'gemini',
            'deepResearchFormatterModel' => 'gemini-2.5-flash',
        ]);

        $report = app(AuditConfigurationCheckService::class)->check();

        $this->assertTrue($report['ready']);
        $messages = collect($report['groups'])->flatMap(fn (array $group) => $group['items'])->pluck('message')->implode("\n");
        $this->assertStringContainsString('gemini_deep_research / deep-research-preview-04-2026', $messages);
        $this->assertStringContainsString('gemini / gemini-2.5-pro', $messages);
    }
}
