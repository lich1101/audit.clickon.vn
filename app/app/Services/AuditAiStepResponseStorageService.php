<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class AuditAiStepResponseStorageService
{
    /**
     * @return array{
     *     rawTextPath: string,
     *     rawTextBytes: int,
     *     rawTextTruncated: bool,
     *     rawTextOriginalBytes: int,
     *     rawTextPreview: string
     * }
     */
    public function store(string $runPublicId, string $step, string $rawText): array
    {
        $maxBytes = $this->maxBytes();
        $originalBytes = strlen($rawText);
        $truncated = false;

        if ($maxBytes > 0 && $originalBytes > $maxBytes) {
            $rawText = substr($rawText, 0, $maxBytes);
            $truncated = true;
        }

        $safeStep = preg_replace('/[^a-z0-9_\-]/i', '_', $step) ?: 'step';
        $relativePath = "audit-ai-responses/{$runPublicId}/{$safeStep}.txt";

        Storage::disk('local')->put($relativePath, $rawText);

        return [
            'rawTextPath' => $relativePath,
            'rawTextBytes' => strlen($rawText),
            'rawTextTruncated' => $truncated,
            'rawTextOriginalBytes' => $originalBytes,
            'rawTextPreview' => mb_substr($rawText, 0, 4000),
        ];
    }

    public function read(string $relativePath): ?string
    {
        if (! Storage::disk('local')->exists($relativePath)) {
            return null;
        }

        return Storage::disk('local')->get($relativePath);
    }

    public function maxBytes(): int
    {
        return (int) config('services.audit.max_ai_step_response_bytes', 0);
    }
}
