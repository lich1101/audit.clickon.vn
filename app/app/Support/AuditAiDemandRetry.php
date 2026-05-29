<?php

namespace App\Support;

class AuditAiDemandRetry
{
    /**
     * Lỗi tạm thời do model/API quá tải — retry đến khi thành công hoặc gặp lỗi khác.
     */
    public static function isRecoverable(int $status, string $message): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $message) ?? $message));

        foreach (self::messageNeedles() as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return in_array($status, [503, 529], true);
    }

    /**
     * @return list<string>
     */
    private static function messageNeedles(): array
    {
        return [
            'high demand',
            'experiencing high demand',
            'spikes in demand',
            'model is overloaded',
            'overloaded',
            'resource exhausted',
            'temporarily unavailable',
            'service unavailable',
            'capacity',
        ];
    }

    public static function sleepMs(): int
    {
        return max(1000, (int) config('services.audit.ai_demand_retry_sleep_ms', 5000));
    }

    /**
     * 0 = không giới hạn số lần retry (chỉ dừng khi thành công hoặc lỗi không recoverable).
     */
    public static function maxAttempts(): int
    {
        return max(0, (int) config('services.audit.ai_demand_retry_max_attempts', 0));
    }
}
