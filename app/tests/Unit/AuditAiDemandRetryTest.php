<?php

namespace Tests\Unit;

use App\Support\AuditAiDemandRetry;
use Tests\TestCase;

class AuditAiDemandRetryTest extends TestCase
{
    public function test_detects_gemini_high_demand_message(): void
    {
        $message = 'This model is currently experiencing high demand. Spikes in demand are usually temporary';

        $this->assertTrue(AuditAiDemandRetry::isRecoverable(503, $message));
    }

    public function test_detects_http_503_without_specific_message(): void
    {
        $this->assertTrue(AuditAiDemandRetry::isRecoverable(503, 'Service Unavailable'));
    }

    public function test_does_not_retry_auth_errors(): void
    {
        $this->assertFalse(AuditAiDemandRetry::isRecoverable(401, 'API key not valid'));
        $this->assertFalse(AuditAiDemandRetry::isRecoverable(400, 'Invalid JSON schema'));
    }

    public function test_does_not_retry_generic_rate_limit_without_demand_signal(): void
    {
        $this->assertFalse(AuditAiDemandRetry::isRecoverable(429, 'Quota exceeded for metric'));
    }
}
