<?php

namespace Tests\Unit;

use App\Services\AuditGeminiPdfAttachmentService;
use App\Services\AuditSettingsService;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class AuditGeminiPdfAttachmentServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_resolve_slot_for_step3_batch_onpage(): void
    {
        $service = new AuditGeminiPdfAttachmentService(Mockery::mock(AuditSettingsService::class));

        $this->assertSame(
            AuditGeminiPdfAttachmentService::SLOT_STEP3_AI,
            $service->resolveSlotForPersistStep('batch_onpage_audit_0_49')
        );
        $this->assertSame(
            AuditGeminiPdfAttachmentService::SLOT_STEP3_FORMATTER,
            $service->resolveSlotForPersistStep('batch_onpage_audit_formatter_0_49')
        );
        $this->assertSame(
            AuditGeminiPdfAttachmentService::SLOT_STEP2_AI,
            $service->resolveSlotForPersistStep('batch_keyword_category_mapping_0_59')
        );
    }

    public function test_build_gemini_user_parts_includes_inline_pdf_when_available(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('audit-gemini-attachments/step3_ai.pdf', '%PDF-1.4 sample');

        $settings = Mockery::mock(AuditSettingsService::class);
        $settings->shouldReceive('getAuditSettings')->andReturn([
            'geminiPdfAttachments' => [
                'step3_ai' => [
                    'slot' => 'step3_ai',
                    'path' => 'audit-gemini-attachments/step3_ai.pdf',
                    'originalName' => 'checklist.pdf',
                    'bytes' => 16,
                    'uploadedAt' => now()->toIso8601String(),
                ],
            ],
        ]);

        $service = new AuditGeminiPdfAttachmentService($settings);
        $attachment = $service->getAttachment('step3_ai');
        $parts = $service->buildGeminiUserParts('Audit these URLs', $attachment);

        $this->assertSame('Audit these URLs', $parts[0]['text']);
        $this->assertSame('application/pdf', $parts[1]['inline_data']['mime_type']);
        $this->assertSame(base64_encode('%PDF-1.4 sample'), $parts[1]['inline_data']['data']);
    }

    public function test_build_deep_research_pdf_appendix_mentions_filename_and_uri(): void
    {
        $service = new AuditGeminiPdfAttachmentService(Mockery::mock(AuditSettingsService::class));

        $appendix = $service->buildDeepResearchPdfAppendix([
            'originalName' => 'seo-checklist.pdf',
            'geminiFileUri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc',
        ]);

        $this->assertStringContainsString('seo-checklist.pdf', $appendix);
        $this->assertStringContainsString('files/abc', $appendix);
    }
}
