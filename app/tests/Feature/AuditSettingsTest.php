<?php

namespace Tests\Feature;

use App\Services\AiModelCatalogService;
use App\Services\AuditSettingsService;
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
            'maxParallelItems' => 3,
            'step2BatchSize' => 60,
            'step3BatchSize' => 30,
        ]);

        $this->assertSame('gemini', $settings['step2AiProvider']);
        $this->assertSame('gemini-2.5-flash', $settings['step2AiModel']);
        $this->assertSame('gemini_deep_research', $settings['step3AiProvider']);
        $this->assertSame('deep-research-pro-preview-12-2025', $settings['step3AiModel']);
    }

    public function test_deep_research_catalog_includes_current_and_legacy_agents(): void
    {
        $catalog = app(AiModelCatalogService::class)->listForProvider('gemini_deep_research');
        $ids = collect($catalog['models'])->pluck('id')->all();

        $this->assertContains('deep-research-pro-preview-12-2025', $ids);
        $this->assertContains('deep-research-preview-04-2026', $ids);
    }
}
