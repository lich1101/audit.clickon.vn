<?php

namespace Tests\Unit;

use App\Support\AuditBatchInputLimiter;
use Tests\TestCase;

class AuditBatchInputLimiterTest extends TestCase
{
    public function test_limits_excerpt_chars_for_large_batches(): void
    {
        config([
            'services.audit.batch_ai_page_excerpt_chars' => 0,
            'services.audit.gemini_max_input_tokens' => 1048576,
            'services.audit.gemini_prompt_reserve_tokens' => 200000,
            'services.audit.gemini_batch_max_tokens_per_url' => 9000,
            'services.audit.gemini_chars_per_token_estimate' => 1.5,
            'services.audit.max_content_chars' => 18000,
        ]);

        $limit100 = AuditBatchInputLimiter::pageExcerptCharLimit(100);
        $limit70 = AuditBatchInputLimiter::pageExcerptCharLimit(70);

        $this->assertLessThan(18000, $limit100);
        $this->assertGreaterThan($limit100, $limit70);
    }
}
