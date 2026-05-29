<?php

namespace Tests\Unit;

use App\Support\AuditGeminiGenerationConfig;
use Tests\TestCase;

class AuditGeminiGenerationConfigTest extends TestCase
{
    public function test_builds_generation_config_for_gemini_25_pro_batch(): void
    {
        config([
            'services.audit.gemini_batch_max_output_tokens' => 65536,
            'services.audit.gemini_batch_thinking_budget' => 4096,
        ]);

        $config = AuditGeminiGenerationConfig::forJsonStep('gemini-2.5-pro', ['type' => 'object'], 'batch');

        $this->assertSame(65536, $config['maxOutputTokens']);
        $this->assertSame('application/json', $config['responseMimeType']);
        $this->assertSame(4096, $config['thinkingConfig']['thinkingBudget']);
    }

    public function test_formatter_profile_uses_smaller_thinking_budget(): void
    {
        config([
            'services.audit.gemini_formatter_max_output_tokens' => 16384,
            'services.audit.gemini_formatter_thinking_budget' => 1024,
        ]);

        $config = AuditGeminiGenerationConfig::forJsonStep('gemini-2.5-pro', ['type' => 'object'], 'formatter');

        $this->assertSame(16384, $config['maxOutputTokens']);
        $this->assertSame(1024, $config['thinkingConfig']['thinkingBudget']);
    }
}
