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
        return $this->storeArtifact(
            runPublicId: $runPublicId,
            step: $step,
            contents: $rawText,
            suffix: 'txt',
            keyPrefix: 'rawText',
        );
    }

    /**
     * @return array{
     *     requestPath: string,
     *     requestBytes: int,
     *     requestTruncated: bool,
     *     requestOriginalBytes: int,
     *     requestPreview: string
     * }
     */
    public function storeRequest(string $runPublicId, string $step, string $contents): array
    {
        return $this->storeArtifact(
            runPublicId: $runPublicId,
            step: $step,
            contents: $contents,
            suffix: 'request.json',
            keyPrefix: 'request',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storeArtifact(string $runPublicId, string $step, string $contents, string $suffix, string $keyPrefix): array
    {
        $maxBytes = $this->maxBytes();
        $originalBytes = strlen($contents);
        $truncated = false;

        if ($maxBytes > 0 && $originalBytes > $maxBytes) {
            $contents = substr($contents, 0, $maxBytes);
            $truncated = true;
        }

        $safeStep = preg_replace('/[^a-z0-9_\-]/i', '_', $step) ?: 'step';
        $safeSuffix = preg_replace('/[^a-z0-9_.\-]/i', '_', $suffix) ?: 'txt';
        $relativePath = "audit-ai-responses/{$runPublicId}/{$safeStep}.{$safeSuffix}";
        $absoluteDir = storage_path('app/private/audit-ai-responses/'.$runPublicId);

        if (! File::isDirectory($absoluteDir)) {
            File::makeDirectory($absoluteDir, 0775, true, true);
        }

        if (! Storage::disk('local')->put($relativePath, $contents)) {
            throw new RuntimeException("Unable to write AI step response file [{$relativePath}]. Check storage/app/private permissions for www-data.");
        }

        return [
            "{$keyPrefix}Path" => $relativePath,
            "{$keyPrefix}Bytes" => strlen($contents),
            "{$keyPrefix}Truncated" => $truncated,
            "{$keyPrefix}OriginalBytes" => $originalBytes,
            "{$keyPrefix}Preview" => mb_substr($contents, 0, 4000),
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
