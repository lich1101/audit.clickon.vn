<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
        $absoluteDir = storage_path('app/private/audit-ai-responses/'.$runPublicId);

        if (! File::isDirectory($absoluteDir)) {
            File::makeDirectory($absoluteDir, 0775, true, true);
        }

        if (! Storage::disk('local')->put($relativePath, $rawText)) {
            throw new RuntimeException("Unable to write AI step response file [{$relativePath}]. Check storage/app/private permissions for www-data.");
        }

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
