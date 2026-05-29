<?php

namespace App\Support;

class AuditGeminiGenerationConfig
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function forJsonStep(string $model, array $schema, string $profile = 'batch'): array
    {
        $temperature = (float) config('services.audit.gemini_temperature', 0.2);
        $topP = (float) config('services.audit.gemini_top_p', 0.95);
        $topK = (int) config('services.audit.gemini_top_k', 40);

        $maxOutput = match ($profile) {
            'formatter' => (int) config('services.audit.gemini_formatter_max_output_tokens', 16384),
            'batch' => (int) config('services.audit.gemini_batch_max_output_tokens', 65536),
            default => (int) config('services.audit.gemini_max_output_tokens', 8192),
        };
        $maxOutput = max(256, min(65536, $maxOutput));

        $config = [
            'temperature' => $temperature,
            'topP' => $topP,
            'topK' => $topK,
            'maxOutputTokens' => $maxOutput,
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
        ];

        if (self::supportsThinking($model)) {
            $budget = match ($profile) {
                'formatter' => (int) config('services.audit.gemini_formatter_thinking_budget', 1024),
                'batch' => (int) config('services.audit.gemini_batch_thinking_budget', 4096),
                default => (int) config('services.audit.gemini_thinking_budget', 2048),
            };

            if ($budget > 0) {
                $config['thinkingConfig'] = [
                    'thinkingBudget' => $budget,
                ];
            }
        }

        return $config;
    }

    private static function supportsThinking(string $model): bool
    {
        $normalized = strtolower(trim($model));

        return str_contains($normalized, '2.5')
            || str_contains($normalized, '2-5')
            || str_contains($normalized, 'thinking');
    }
}
