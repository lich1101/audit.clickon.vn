<?php

namespace App\Support;

class AuditBatchInputLimiter
{
    /**
     * Giới hạn excerpt gửi vào Gemini theo batch size để không vượt input 1.048.576 token (gemini-2.5-pro).
     */
    public static function pageExcerptCharLimit(int $urlCount): int
    {
        $explicit = (int) config('services.audit.batch_ai_page_excerpt_chars', 0);

        if ($explicit > 0) {
            return $explicit;
        }

        $maxInputTokens = (int) config('services.audit.gemini_max_input_tokens', 1048576);
        $promptReserve = (int) config('services.audit.gemini_prompt_reserve_tokens', 200000);
        $available = max(10000, $maxInputTokens - $promptReserve);
        $urlCount = max(1, $urlCount);
        $perUrlTokenBudget = (int) floor($available / $urlCount);
        $perUrlCap = (int) config('services.audit.gemini_batch_max_tokens_per_url', 9000);
        $perUrlTokenBudget = min($perUrlTokenBudget, max(500, $perUrlCap));

        $charsPerToken = (float) config('services.audit.gemini_chars_per_token_estimate', 1.5);
        $charLimit = max(1200, (int) floor($perUrlTokenBudget * $charsPerToken));
        $maxContent = (int) config('services.audit.max_content_chars', 18000);

        return min($maxContent, $charLimit);
    }
}
