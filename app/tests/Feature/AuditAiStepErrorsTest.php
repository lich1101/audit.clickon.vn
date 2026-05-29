<?php

namespace Tests\Feature;

use App\Services\AuditRunService;
use Tests\TestCase;

class AuditAiStepErrorsTest extends TestCase
{
    public function test_compact_ai_step_errors_returns_step2_formatter_failure(): void
    {
        $service = app(AuditRunService::class);

        $errors = $service->compactAiStepErrors([
            'batch_keyword_category_mapping_001_010' => [
                'step' => 'batch_keyword_category_mapping_001_010',
                'status' => 'parsed',
            ],
            'keyword_category_json_formatter_001_010' => [
                'step' => 'keyword_category_json_formatter_001_010',
                'stepLabel' => 'Bước 2.5: formatter JSON',
                'status' => 'parse_failed',
                'parseError' => 'Gemini API lỗi HTTP 503: high demand',
                'provider' => 'gemini',
                'model' => 'gemini-2.5-flash',
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('keyword_category_json_formatter_001_010', $errors[0]['stepKey']);
        $this->assertSame('Bước 2.5: formatter JSON', $errors[0]['stepLabel']);
        $this->assertSame(1, $errors[0]['positionFrom']);
        $this->assertSame(10, $errors[0]['positionTo']);
        $this->assertStringContainsString('503', (string) $errors[0]['errorMessage']);
    }
}
