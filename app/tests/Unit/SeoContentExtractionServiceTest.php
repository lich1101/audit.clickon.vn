<?php

namespace Tests\Unit;

use App\Services\SeoContentExtractionService;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class SeoContentExtractionServiceTest extends TestCase
{
    public function test_parse_jina_reader_text_extracts_markdown_content_block(): void
    {
        $service = new SeoContentExtractionService;
        $method = new ReflectionMethod(SeoContentExtractionService::class, 'parseJinaReaderText');
        $method->setAccessible(true);

        $parsed = $method->invoke($service, implode("\n", [
            'Title: Thu Mua Dây cáp điện giá Cao Nhất',
            '',
            'URL Source: https://example.com/page',
            '',
            'Markdown Content:',
            'Phế Liệu Thiên Long nhận thu mua dây điện cũ.',
            '',
            '## Lợi ích mang lại',
            'Nội dung chi tiết bài viết.',
        ]));

        $this->assertSame('Thu Mua Dây cáp điện giá Cao Nhất', $parsed['title']);
        $this->assertStringContainsString('Phế Liệu Thiên Long nhận thu mua dây điện cũ.', $parsed['markdown']);
        $this->assertStringContainsString('Lợi ích mang lại', $parsed['content']);
        $this->assertStringNotContainsString('URL Source:', $parsed['content']);
    }

    public function test_extract_uses_jina_markdown_content_for_excerpt(): void
    {
        config([
            'services.audit.use_jina' => true,
            'services.audit.jina_base_url' => 'https://r.jina.ai/',
            'services.audit.ai_http_retry_attempts' => 1,
        ]);

        Http::fake([
            'https://r.jina.ai/*' => Http::response(implode("\n", [
                'Title: DỊCH VỤ - THU MUA GIÀN GIÁO CŨ GIÁ CAO',
                'URL Source: https://example.com/gian-giao',
                'Markdown Content:',
                'Thiên Long chuyên THU MUA GIÀN GIÁO CŨ GIÁ CAO tận nơi.',
                '## Tại Sao Nên Chọn Thiên Long',
                'Cam kết giá tốt nhất thị trường.',
            ])),
        ]);

        $page = (new SeoContentExtractionService)->extract('https://example.com/gian-giao');

        $this->assertSame('jina', $page['source']);
        $this->assertSame('DỊCH VỤ - THU MUA GIÀN GIÁO CŨ GIÁ CAO', $page['title']);
        $this->assertStringContainsString('THU MUA GIÀN GIÁO CŨ GIÁ CAO tận nơi', $page['content']);
        $this->assertStringNotContainsString('URL Source:', $page['content']);
        $this->assertContains('Tại Sao Nên Chọn Thiên Long', $page['headings']['h2']);
    }

    public function test_extract_falls_back_to_html_and_picks_longest_content_node(): void
    {
        config([
            'services.audit.use_jina' => true,
            'services.audit.jina_base_url' => 'https://r.jina.ai/',
            'services.audit.ai_http_retry_attempts' => 1,
        ]);

        Http::fake([
            'https://r.jina.ai/*' => Http::response('', 503),
            'https://example.com/article' => Http::response(<<<'HTML'
<!doctype html>
<html>
<head><title>Article title</title></head>
<body>
  <div class="wrap-content">0986 117 289</div>
  <div class="content-main w-clear">
    <h1>Article title</h1>
    <p>Thiên Long chuyên thu mua phế liệu với nội dung bài viết đủ dài để vượt ngưỡng lọc nội dung chính của hệ thống audit.</p>
    <p>Đoạn thứ hai mô tả thêm quy trình thu mua, cam kết giá cao và thanh toán nhanh cho khách hàng tại TP.HCM.</p>
  </div>
</body>
</html>
HTML),
        ]);

        $page = (new SeoContentExtractionService)->extract('https://example.com/article');

        $this->assertSame('html', $page['source']);
        $this->assertStringContainsString('Thiên Long chuyên thu mua phế liệu', $page['content']);
        $this->assertStringNotContainsString('0986 117 289', $page['content']);
    }
}
