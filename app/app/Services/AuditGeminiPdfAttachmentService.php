<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AuditGeminiPdfAttachmentService
{
    public const SLOT_STEP2_AI = 'step2_ai';

    public const SLOT_STEP3_AI = 'step3_ai';

    public const SLOT_STEP2_FORMATTER = 'step2_formatter';

    public const SLOT_STEP3_FORMATTER = 'step3_formatter';

    public function __construct(
        private readonly AuditSettingsService $auditSettingsService,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function allowedSlots(): array
    {
        return [
            self::SLOT_STEP2_AI,
            self::SLOT_STEP3_AI,
            self::SLOT_STEP2_FORMATTER,
            self::SLOT_STEP3_FORMATTER,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allAttachments(): array
    {
        $settings = $this->auditSettingsService->getAuditSettings();

        return is_array($settings['geminiPdfAttachments'] ?? null)
            ? $settings['geminiPdfAttachments']
            : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAttachment(string $slot): ?array
    {
        $attachment = $this->allAttachments()[$slot] ?? null;

        return is_array($attachment) ? $attachment : null;
    }

    public function resolveSlotForPersistStep(?string $persistStep): ?string
    {
        if (! is_string($persistStep) || $persistStep === '') {
            return null;
        }

        if (str_contains($persistStep, 'keyword_category') && str_contains($persistStep, 'formatter')) {
            return self::SLOT_STEP2_FORMATTER;
        }

        if (str_contains($persistStep, 'batch_keyword_category') || str_contains($persistStep, 'keyword_category_mapping')) {
            return self::SLOT_STEP2_AI;
        }

        if (str_contains($persistStep, 'onpage') && str_contains($persistStep, 'formatter')) {
            return self::SLOT_STEP3_FORMATTER;
        }

        if (str_contains($persistStep, 'batch_onpage') || str_contains($persistStep, 'onpage_audit')) {
            return self::SLOT_STEP3_AI;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function upload(string $slot, UploadedFile $file): array
    {
        $this->assertAllowedSlot($slot);

        if (strtolower((string) $file->getClientOriginalExtension()) !== 'pdf' && $file->getMimeType() !== 'application/pdf') {
            throw new RuntimeException('Only PDF files are supported.');
        }

        $maxBytes = max(1, (int) config('services.audit.gemini_pdf_max_bytes', 10 * 1024 * 1024));

        if ($file->getSize() > $maxBytes) {
            throw new RuntimeException('PDF exceeds maximum upload size.');
        }

        $relativePath = "audit-gemini-attachments/{$slot}.pdf";
        Storage::disk('local')->put($relativePath, $file->get());

        $attachment = [
            'slot' => $slot,
            'path' => $relativePath,
            'originalName' => $file->getClientOriginalName(),
            'bytes' => Storage::disk('local')->size($relativePath),
            'uploadedAt' => now()->toIso8601String(),
            'geminiFileUri' => null,
            'geminiFileName' => null,
        ];

        try {
            $uploaded = $this->syncToGeminiFilesApi($relativePath, (string) $file->getClientOriginalName());
            $attachment['geminiFileUri'] = $uploaded['uri'] ?? null;
            $attachment['geminiFileName'] = $uploaded['name'] ?? null;
        } catch (\Throwable) {
            // Inline PDF still works for generateContent even if Files API sync fails.
        }

        $this->persistAttachment($slot, $attachment);

        return $attachment;
    }

    public function delete(string $slot): void
    {
        $this->assertAllowedSlot($slot);
        $existing = $this->getAttachment($slot);
        $path = is_string($existing['path'] ?? null) ? $existing['path'] : null;

        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }

        $attachments = $this->allAttachments();
        unset($attachments[$slot]);
        $this->persistAllAttachments($attachments);
    }

    /**
     * @param  array<string, mixed>|null  $attachment
     * @return array<int, array<string, mixed>>
     */
    public function buildGeminiUserParts(string $userPrompt, ?array $attachment): array
    {
        $parts = [
            ['text' => $userPrompt],
        ];

        $inline = $this->buildInlineDataPart($attachment);

        if ($inline !== null) {
            $parts[] = $inline;
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>|null  $attachment
     */
    public function buildDeepResearchPdfAppendix(?array $attachment): string
    {
        if ($attachment === null) {
            return '';
        }

        $lines = [
            '=== ADMIN ATTACHED REFERENCE PDF ===',
            'Original filename: '.((string) ($attachment['originalName'] ?? 'reference.pdf')),
            'Use this PDF as supplemental checklist/reference material when scoring.',
        ];

        if (is_string($attachment['geminiFileUri'] ?? null) && $attachment['geminiFileUri'] !== '') {
            $lines[] = 'Gemini file URI: '.$attachment['geminiFileUri'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>|null  $attachment
     * @return array<string, mixed>|null
     */
    private function buildInlineDataPart(?array $attachment): ?array
    {
        if ($attachment === null) {
            return null;
        }

        $path = trim((string) ($attachment['path'] ?? ''));

        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $binary = Storage::disk('local')->get($path);

        if (! is_string($binary) || $binary === '') {
            return null;
        }

        return [
            'inline_data' => [
                'mime_type' => 'application/pdf',
                'data' => base64_encode($binary),
            ],
        ];
    }

    /**
     * @return array{uri?: string, name?: string}
     */
    private function syncToGeminiFilesApi(string $relativePath, string $displayName): array
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $absolutePath = Storage::disk('local')->path($relativePath);
        $bytes = file_get_contents($absolutePath);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Unable to read uploaded PDF.');
        }

        /** @var Response $start */
        $start = Http::withHeaders([
            'x-goog-api-key' => $apiKey,
            'X-Goog-Upload-Protocol' => 'resumable',
            'X-Goog-Upload-Command' => 'start',
            'X-Goog-Upload-Header-Content-Length' => (string) strlen($bytes),
            'X-Goog-Upload-Header-Content-Type' => 'application/pdf',
            'Content-Type' => 'application/json',
        ])->post('https://generativelanguage.googleapis.com/upload/v1beta/files', [
            'file' => [
                'display_name' => $displayName,
            ],
        ]);

        if (! $start->successful()) {
            throw new RuntimeException('Gemini Files API start upload failed.');
        }

        $uploadUrl = $start->header('X-Goog-Upload-URL');

        if (! is_string($uploadUrl) || $uploadUrl === '') {
            throw new RuntimeException('Gemini Files API did not return upload URL.');
        }

        /** @var Response $finish */
        $finish = Http::withHeaders([
            'Content-Length' => (string) strlen($bytes),
            'X-Goog-Upload-Offset' => '0',
            'X-Goog-Upload-Command' => 'upload, finalize',
        ])
            ->withBody($bytes, 'application/pdf')
            ->post($uploadUrl);

        if (! $finish->successful()) {
            throw new RuntimeException('Gemini Files API finalize upload failed.');
        }

        $payload = $finish->json();
        $file = is_array($payload['file'] ?? null) ? $payload['file'] : [];

        return [
            'uri' => is_string($file['uri'] ?? null) ? $file['uri'] : null,
            'name' => is_string($file['name'] ?? null) ? $file['name'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function persistAttachment(string $slot, array $attachment): void
    {
        $attachments = $this->allAttachments();
        $attachments[$slot] = $attachment;
        $this->persistAllAttachments($attachments);
    }

    /**
     * @param  array<string, array<string, mixed>>  $attachments
     */
    private function persistAllAttachments(array $attachments): void
    {
        $this->auditSettingsService->updateGeminiPdfAttachments($attachments);
    }

    private function assertAllowedSlot(string $slot): void
    {
        if (! in_array($slot, $this->allowedSlots(), true)) {
            throw new RuntimeException("Unsupported Gemini PDF slot [{$slot}].");
        }
    }
}
