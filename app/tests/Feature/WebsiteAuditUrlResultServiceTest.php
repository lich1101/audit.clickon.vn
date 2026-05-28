<?php

namespace Tests\Feature;

use App\Models\AuditRun;
use App\Models\AuditRunItem;
use App\Models\WebsiteAuditUrlResult;
use App\Services\WebsiteAuditUrlResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteAuditUrlResultServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_item_keeps_previous_audit_payload_but_stores_latest_failure_state(): void
    {
        $existing = WebsiteAuditUrlResult::query()->create([
            'website_id' => 'website-test',
            'target_url_hash' => hash('sha256', 'https://example.com/post-1'),
            'target_url' => 'https://example.com/post-1',
            'status' => 'completed',
            'page_title' => 'Bai cu',
            'primary_keyword' => 'keyword cu',
            'category_name' => 'Danh muc cu',
            'category_url' => 'https://example.com/danh-muc-cu',
            'category_match_reason' => 'Khớp lần chạy cũ',
            'audit_score' => 88,
            'audit_findings' => "Điểm kỹ thuật SEO: 21/24\nĐiểm nội dung: 5/6",
            'audit_recommendations' => "Giữ title hiện tại\nBổ sung 1 internal link",
            'content_revision_direction' => 'Giữ nguyên. Bài viết đã có nền tảng tốt. Chỉ cần cập nhật nhẹ các điểm phụ. Ưu tiên tối ưu nội bộ.',
            'error_message' => null,
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4.1',
            'audited_at' => now()->subDay(),
        ]);

        $run = $this->makeRun();
        $item = AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => 1,
            'target_url' => 'https://example.com/post-1',
            'status' => 'failed',
            'extraction_source' => 'url_only_batch_step3_running',
            'page_title' => null,
            'primary_keyword' => 'keyword moi tu step 2',
            'category_name' => 'Danh muc moi',
            'category_url' => 'https://example.com/danh-muc-moi',
            'category_match_reason' => 'Khớp lần chạy mới',
            'error_message' => 'Bước 3 lỗi JSON.',
            'completed_at' => now(),
        ]);

        $result = app(WebsiteAuditUrlResultService::class)->upsertFromItem($item->fresh('run'));
        $result->refresh();

        $this->assertTrue($result->is($existing->fresh()));
        $this->assertSame('failed', $result->status);
        $this->assertSame('keyword moi tu step 2', $result->primary_keyword);
        $this->assertSame('Danh muc moi', $result->category_name);
        $this->assertSame('https://example.com/danh-muc-moi', $result->category_url);
        $this->assertSame('Khớp lần chạy mới', $result->category_match_reason);
        $this->assertSame(88, $result->audit_score);
        $this->assertSame("Điểm kỹ thuật SEO: 21/24\nĐiểm nội dung: 5/6", $result->audit_findings);
        $this->assertSame("Giữ title hiện tại\nBổ sung 1 internal link", $result->audit_recommendations);
        $this->assertSame('Giữ nguyên. Bài viết đã có nền tảng tốt. Chỉ cần cập nhật nhẹ các điểm phụ. Ưu tiên tối ưu nội bộ.', $result->content_revision_direction);
        $this->assertSame('Bước 3 lỗi JSON.', $result->error_message);
        $this->assertSame('openai', $result->ai_provider);
        $this->assertSame('gpt-4.1', $result->ai_model);
        $this->assertTrue($result->audited_at?->equalTo($existing->audited_at));
        $this->assertSame($run->id, $result->latest_audit_run_id);
        $this->assertSame($item->id, $result->latest_audit_run_item_id);
    }

    public function test_step2_only_completion_updates_seed_data_without_erasing_previous_audit_result(): void
    {
        $existing = WebsiteAuditUrlResult::query()->create([
            'website_id' => 'website-test',
            'target_url_hash' => hash('sha256', 'https://example.com/post-1'),
            'target_url' => 'https://example.com/post-1',
            'status' => 'completed',
            'page_title' => 'Bai cu',
            'primary_keyword' => 'keyword cu',
            'category_name' => 'Danh muc cu',
            'category_url' => 'https://example.com/danh-muc-cu',
            'category_match_reason' => 'Khớp lần chạy cũ',
            'audit_score' => 91,
            'audit_findings' => "Điểm kỹ thuật SEO: 23/24\nĐiểm nội dung: 5/6",
            'audit_recommendations' => "Giữ heading hiện tại\nBổ sung CTA rõ hơn",
            'content_revision_direction' => 'Giữ nguyên. Bài viết đã tối ưu khá tốt. Chỉ cần tinh chỉnh CTA và update nhẹ. Ưu tiên giữ cấu trúc hiện tại.',
            'error_message' => null,
            'ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-pro',
            'audited_at' => now()->subHours(8),
        ]);

        $run = $this->makeRun([
            'stop_after_step' => 2,
        ]);
        $item = AuditRunItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'audit_run_id' => $run->id,
            'position' => 1,
            'target_url' => 'https://example.com/post-1',
            'status' => 'completed',
            'extraction_source' => 'url_only_batch_step2_only_completed',
            'page_title' => 'Bai moi tu step 2',
            'primary_keyword' => 'keyword moi tu step 2',
            'category_name' => 'Danh muc moi tu step 2',
            'category_url' => 'https://example.com/danh-muc-moi',
            'category_match_reason' => 'Khớp lại ở bước 2',
            'completed_at' => now(),
        ]);

        $result = app(WebsiteAuditUrlResultService::class)->upsertFromItem($item->fresh('run'));
        $result->refresh();

        $this->assertTrue($result->is($existing->fresh()));
        $this->assertSame('completed', $result->status);
        $this->assertSame('Bai moi tu step 2', $result->page_title);
        $this->assertSame('keyword moi tu step 2', $result->primary_keyword);
        $this->assertSame('Danh muc moi tu step 2', $result->category_name);
        $this->assertSame('https://example.com/danh-muc-moi', $result->category_url);
        $this->assertSame('Khớp lại ở bước 2', $result->category_match_reason);
        $this->assertSame(91, $result->audit_score);
        $this->assertSame("Điểm kỹ thuật SEO: 23/24\nĐiểm nội dung: 5/6", $result->audit_findings);
        $this->assertSame("Giữ heading hiện tại\nBổ sung CTA rõ hơn", $result->audit_recommendations);
        $this->assertSame('Giữ nguyên. Bài viết đã tối ưu khá tốt. Chỉ cần tinh chỉnh CTA và update nhẹ. Ưu tiên giữ cấu trúc hiện tại.', $result->content_revision_direction);
        $this->assertNull($result->error_message);
        $this->assertSame('gemini', $result->ai_provider);
        $this->assertSame('gemini-2.5-pro', $result->ai_model);
        $this->assertTrue($result->audited_at?->equalTo($existing->audited_at));
        $this->assertSame($run->id, $result->latest_audit_run_id);
        $this->assertSame($item->id, $result->latest_audit_run_item_id);
    }

    private function makeRun(array $overrides = []): AuditRun
    {
        return AuditRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => 'website-test',
            'website_name' => 'Website test',
            'website_url' => 'https://example.com',
            'user_uid' => 'user-test',
            'user_email' => 'test@example.com',
            'status' => 'processing',
            'workflow' => AuditRun::WORKFLOW_STANDARD,
            'target_urls' => ['https://example.com/post-1'],
            'categories' => [],
            'category_contexts' => [],
            'checklist_text' => 'Checklist test',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4.1',
            'step2_ai_provider' => 'openai',
            'step2_ai_model' => 'gpt-4.1',
            'step3_ai_provider' => 'openai',
            'step3_ai_model' => 'gpt-4.1',
            'step2_formatter_provider' => 'gemini',
            'step2_formatter_model' => 'gemini-2.5-flash',
            'step3_formatter_provider' => 'gemini',
            'step3_formatter_model' => 'gemini-2.5-flash',
            'total_urls' => 1,
            'processed_urls' => 0,
            'completed_urls' => 0,
            'failed_urls' => 0,
            'started_at' => now(),
            ...$overrides,
        ]);
    }
}
