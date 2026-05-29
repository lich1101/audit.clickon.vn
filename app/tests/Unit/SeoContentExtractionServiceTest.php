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

    public function test_extract_markdown_headings_skips_image_like_headings(): void
    {
        $service = new SeoContentExtractionService;
        $method = new ReflectionMethod(SeoContentExtractionService::class, 'extractMarkdownHeadings');
        $method->setAccessible(true);

        $headings = $method->invoke($service, implode("\n", [
            '# Tiêu đề chính',
            '## Mục hợp lệ',
            '## ![Image 2](https://example.com/image.jpg)',
            '### Chi tiết',
        ]));

        $this->assertSame(['Tiêu đề chính'], $headings['h1']);
        $this->assertSame(['Mục hợp lệ'], $headings['h2']);
        $this->assertSame(['Chi tiết'], $headings['h3']);
    }

    public function test_parse_html_document_preserves_vietnamese_utf8_headings(): void
    {
        $service = new SeoContentExtractionService;
        $method = new ReflectionMethod(SeoContentExtractionService::class, 'parseHtmlDocument');
        $method->setAccessible(true);

        $parsed = $method->invoke($service, <<<'HTML'
<!doctype html>
<html>
<body>
  <h1>1KG CHÌ GIÁ BAO NHIÊU? THU MUA CHÌ PHẾ LIỆU</h1>
  <h2>Giá 1kg Chì Phế Liệu Hiện Nay?</h2>
  <p>Nội dung bài viết đủ dài để audit SEO onpage theo checklist Clickon với tiếng Việt có dấu.</p>
</body>
</html>
HTML, 'https://example.com/page');

        $this->assertSame(['1KG CHÌ GIÁ BAO NHIÊU? THU MUA CHÌ PHẾ LIỆU'], $parsed['headings']['h1']);
        $this->assertSame(['Giá 1kg Chì Phế Liệu Hiện Nay?'], $parsed['headings']['h2']);
        $this->assertStringContainsString('tiếng Việt có dấu', $parsed['content']);
        $this->assertStringContainsString('## Giá 1kg Chì Phế Liệu Hiện Nay?', $parsed['content']);
    }

    public function test_build_audit_content_removes_sidebar_and_related_sections(): void
    {
        $service = new SeoContentExtractionService;
        $method = new ReflectionMethod(SeoContentExtractionService::class, 'parseHtmlDocument');
        $method->setAccessible(true);

        $parsed = $method->invoke($service, <<<'HTML'
<!doctype html>
<html><body>
  <div class="wrap-content">0986 117 289</div>
  <main class="content-main">
    <h1>Thu mua chì phế liệu</h1>
    <p>Nội dung chính đủ dài để audit SEO onpage theo checklist Clickon với tiếng Việt có dấu và mô tả chi tiết quy trình thu mua.</p>
    <p>Thiên Long cam kết giá cao, thanh toán nhanh và tận nơi tại TP.HCM cho khách hàng cá nhân và doanh nghiệp.</p>
    <div class="related-posts">Bài viết liên quan</div>
    <p>Danh mục thu mua khác không thuộc bài viết chính.</p>
  </main>
</body></html>
HTML, 'https://example.com/page');

        $this->assertStringContainsString('Nội dung chính đủ dài', $parsed['content']);
        $this->assertStringNotContainsString('0986 117 289', $parsed['content']);
        $this->assertStringNotContainsString('Danh mục thu mua', $parsed['content']);
        $this->assertSame(0, $parsed['internalLinkCount']);
    }

    public function test_parse_html_document_builds_checklist_evidence_for_pdf_criteria(): void
    {
        $service = new SeoContentExtractionService;
        $method = new ReflectionMethod(SeoContentExtractionService::class, 'parseHtmlDocument');
        $method->setAccessible(true);

        $parsed = $method->invoke($service, <<<'HTML'
<!doctype html>
<html>
<head>
  <title>Thu mua chì phế liệu giá cao 2026 tại TP.HCM</title>
  <meta name="description" content="Dịch vụ thu mua chì phế liệu giá cao, thanh toán nhanh, tận nơi tại TP.HCM cho khách hàng cá nhân và doanh nghiệp cần bán phế liệu." />
</head>
<body>
<main class="content-main">
  <h1>Thu mua chì phế liệu giá cao 2026</h1>
  <p>Thiên Long thu mua chì phế liệu tận nơi. Xem <a href="https://example.com/thu-mua-chi-phe-lieu">bài viết này</a> hoặc về <a href="https://example.com/">trang chủ</a>.</p>
  <p>Nội dung bài viết đủ dài để audit SEO onpage theo checklist Clickon với tiếng Việt có dấu và mô tả chi tiết quy trình thu mua.</p>
  <h2>Giá thu mua chì phế liệu</h2>
  <ul><li>Giá cao</li><li>Thanh toán nhanh</li></ul>
  <img src="https://example.com/uploads/thu-mua-chi-phe-lieu.jpg" alt="Thu mua chì phế liệu giá cao" />
  <img src="https://example.com/uploads/bang-gia.jpg" alt="" />
  <a href="https://example.com/bang-gia">Xem bảng giá</a>
  <a href="https://example.com/lien-he">Liên hệ ngay</a>
  <h2>FAQ thu mua chì phế liệu</h2>
  <p>Câu hỏi thường gặp về giá và quy trình thu mua.</p>
</main>
</body></html>
HTML, 'https://example.com/thu-mua-chi-phe-lieu');

        $evidence = $parsed['checklistEvidence'];
        $this->assertGreaterThan(0, $evidence['title']['length']);
        $this->assertTrue($evidence['title']['hasNumber']);
        $this->assertTrue($evidence['url']['usesHyphens']);
        $this->assertFalse($evidence['url']['hasDiacritics']);
        $this->assertTrue($evidence['sapo']['hasSelfLink']);
        $this->assertTrue($evidence['sapo']['hasHomepageLink']);
        $this->assertSame(2, $evidence['images']['count']);
        $this->assertSame(1, $evidence['images']['missingAltCount']);
        $this->assertGreaterThanOrEqual(2, $evidence['internalLinks']['count']);
        $this->assertTrue($evidence['structure']['hasBulletList']);
        $this->assertTrue($evidence['cta']['detected']);
        $this->assertTrue($evidence['faq']['detected']);
        $this->assertContains(1, $evidence['verifiableCriteria']);
        $this->assertContains(3, $evidence['verifiableCriteria']);
        $this->assertContains(15, $evidence['verifiableCriteria']);
        $this->assertContains(5, $evidence['externalDataRequired']);
    }

    public function test_finalize_extracted_page_marks_audit_ready_for_substantive_content(): void
    {
        config([
            'services.audit.content_provider' => 'firecrawl',
            'services.audit.firecrawl_base_url' => 'http://firecrawl.test',
            'services.audit.min_audit_content_words' => 20,
            'services.audit.min_audit_content_chars' => 100,
            'services.audit.ai_http_retry_attempts' => 1,
        ]);

        Http::fake([
            'http://firecrawl.test/v1/scrape' => Http::response([
                'success' => true,
                'data' => [
                    'metadata' => ['title' => 'Thu mua phế liệu'],
                    'markdown' => '',
                    'html' => <<<'HTML'
<!doctype html><html><body><main><h1>Thu mua phế liệu</h1><p>Nội dung bài viết đủ dài để audit SEO onpage theo checklist Clickon với tiếng Việt có dấu.</p></main></body></html>
HTML,
                ],
            ]),
        ]);

        $page = (new SeoContentExtractionService)->extract('https://example.com/page');

        $this->assertTrue($page['metrics']['auditReady']);
        $this->assertSame([], $page['metrics']['contentQualityIssues']);
        $this->assertIsArray($page['metrics']['checklistEvidence'] ?? null);
    }

    public function test_extract_uses_jina_markdown_content_for_excerpt(): void
    {
        config([
            'services.audit.content_provider' => 'jina',
            'services.audit.use_jina' => true,
            'services.audit.jina_base_url' => 'https://r.jina.ai/',
            'services.audit.jina_html_meta_fallback' => false,
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
                '![Thu mua giàn giáo](https://example.com/images/gian-giao.jpg)',
                'Xem thêm [bảng giá phế liệu](/bang-gia) hoặc [Facebook](https://facebook.com/example).',
            ])),
        ]);

        $page = (new SeoContentExtractionService)->extract('https://example.com/gian-giao');

        $this->assertSame('jina', $page['source']);
        $this->assertSame('DỊCH VỤ - THU MUA GIÀN GIÁO CŨ GIÁ CAO', $page['title']);
        $this->assertStringContainsString('THU MUA GIÀN GIÁO CŨ GIÁ CAO tận nơi', $page['content']);
        $this->assertStringNotContainsString('URL Source:', $page['content']);
        $this->assertContains('Tại Sao Nên Chọn Thiên Long', $page['headings']['h2']);
        $this->assertSame(1, $page['metrics']['imageCount']);
        $this->assertSame(0, $page['metrics']['missingAltCount']);
        $this->assertSame(1, $page['metrics']['internalLinkCount']);
        $this->assertSame(1, $page['metrics']['externalLinkCount']);
    }

    public function test_jina_extraction_falls_back_to_html_meta_description(): void
    {
        config([
            'services.audit.content_provider' => 'jina',
            'services.audit.use_jina' => true,
            'services.audit.jina_base_url' => 'https://r.jina.ai/',
            'services.audit.jina_html_meta_fallback' => true,
            'services.audit.ai_http_retry_attempts' => 1,
        ]);

        Http::fake([
            'https://r.jina.ai/*' => Http::response(implode("\n", [
                'Title: Thu mua phế liệu giá cao',
                'URL Source: https://example.com/page',
                'Markdown Content:',
                'Nội dung bài viết đủ dài để audit SEO onpage theo checklist Clickon.',
            ])),
            'https://example.com/page' => Http::response(<<<'HTML'
<!doctype html>
<html>
<head>
  <title>Thu mua phế liệu giá cao</title>
  <meta name="description" content="Dịch vụ thu mua phế liệu tận nơi, giá cao, thanh toán nhanh tại TP.HCM.">
  <link rel="canonical" href="https://example.com/page" />
</head>
<body><p>Nội dung</p></body>
</html>
HTML),
        ]);

        $page = (new SeoContentExtractionService)->extract('https://example.com/page');

        $this->assertSame('jina', $page['source']);
        $this->assertSame('Dịch vụ thu mua phế liệu tận nơi, giá cao, thanh toán nhanh tại TP.HCM.', $page['metaDescription']);
        $this->assertSame('https://example.com/page', $page['canonicalUrl']);
        $this->assertTrue($page['metrics']['hasCanonical']);
    }

    public function test_extract_falls_back_to_html_and_picks_longest_content_node(): void
    {
        config([
            'services.audit.content_provider' => 'jina',
            'services.audit.use_jina' => true,
            'services.audit.jina_base_url' => 'https://r.jina.ai/',
            'services.audit.jina_html_meta_fallback' => false,
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

    public function test_extract_uses_firecrawl_when_configured(): void
    {
        config([
            'services.audit.content_provider' => 'firecrawl',
            'services.audit.firecrawl_base_url' => 'http://firecrawl.test',
            'services.audit.firecrawl_only_main_content' => true,
            'services.audit.ai_http_retry_attempts' => 1,
        ]);

        Http::fake([
            'http://firecrawl.test/v1/scrape' => Http::response([
                'success' => true,
                'data' => [
                    'metadata' => [
                        'title' => 'Thu mua phế liệu giá cao',
                        'description' => 'Mô tả meta 120 ký tự dùng cho audit SEO onpage checklist Clickon.',
                        'og:url' => 'https://example.com/page',
                    ],
                    'markdown' => "# Thu mua phế liệu giá cao\n\nNội dung bài viết chi tiết.\n\n## Quy trình thu mua\n\nCam kết giá tốt.\n\n![Thu mua phế liệu](https://example.com/a.jpg)\n\n[Xem bảng giá](/bang-gia)",
                    'html' => <<<'HTML'
<!doctype html>
<html>
<head>
  <title>Thu mua phế liệu giá cao</title>
  <meta name="description" content="Mô tả meta 120 ký tự dùng cho audit SEO onpage checklist Clickon." />
  <link rel="canonical" href="https://example.com/page" />
</head>
<body>
  <main class="content-main">
    <h1>Thu mua phế liệu giá cao</h1>
    <p>Nội dung bài viết chi tiết đủ dài để audit SEO onpage theo checklist Clickon.</p>
    <h2>Quy trình thu mua</h2>
    <p>Cam kết giá tốt.</p>
    <img src="https://example.com/a.jpg" alt="Thu mua phế liệu" />
    <a href="/bang-gia">Xem bảng giá</a>
  </main>
</body>
</html>
HTML,
                    'links' => [
                        'https://example.com/bang-gia',
                        'https://facebook.com/example',
                    ],
                ],
            ]),
        ]);

        $page = (new SeoContentExtractionService)->extract('https://example.com/page');

        $this->assertSame('firecrawl', $page['source']);
        $this->assertSame('Thu mua phế liệu giá cao', $page['title']);
        $this->assertStringContainsString('Mô tả meta 120 ký tự', $page['metaDescription']);
        $this->assertSame('https://example.com/page', $page['canonicalUrl']);
        $this->assertContains('Quy trình thu mua', $page['headings']['h2']);
        $this->assertGreaterThanOrEqual(1, $page['metrics']['imageCount']);
        $this->assertGreaterThanOrEqual(1, $page['metrics']['internalLinkCount']);
        $this->assertStringContainsString('Nội dung bài viết chi tiết', $page['content']);
    }

    public function test_firecrawl_prefers_clean_html_main_content_over_noisy_markdown(): void
    {
        config([
            'services.audit.content_provider' => 'firecrawl',
            'services.audit.firecrawl_base_url' => 'http://firecrawl.test',
            'services.audit.firecrawl_only_main_content' => true,
            'services.audit.firecrawl_min_html_content_chars' => 500,
            'services.audit.ai_http_retry_attempts' => 1,
        ]);

        Http::fake([
            'http://firecrawl.test/v1/scrape' => Http::response([
                'success' => true,
                'data' => [
                    'metadata' => [
                        'title' => '1KG CHÌ GIÁ BAO NHIÊU? THU MUA CHÌ PHẾ LIỆU',
                        'description' => 'Mô tả meta bài viết chì phế liệu.',
                    ],
                    'markdown' => implode("\n", [
                        '* Menu item 1',
                        '* Menu item 2',
                        '1KG CHÌ GIÁ BAO NHIÊU? THU MUA CHÌ PHẾ LIỆU',
                        str_repeat('Nội dung markdown dài kèm menu và sidebar. ', 120),
                        'Copy link AddToAny More…',
                    ]),
                    'html' => <<<'HTML'
<!doctype html>
<html><body>
<main class="content-main">
  <h1>1KG CHÌ GIÁ BAO NHIÊU? THU MUA CHÌ PHẾ LIỆU</h1>
  <p>Giá thu mua chì phế liệu luôn là vấn đề được nhiều người quan tâm trong lĩnh vực tái chế.</p>
  <p>Thiên Long cập nhật bảng giá thu mua chì phế liệu chi tiết, minh bạch và thanh toán nhanh cho khách hàng.</p>
  <p>Đoạn nội dung chính đủ dài để audit SEO onpage theo checklist Clickon với tiếng Việt có dấu.</p>
</main>
</body></html>
HTML,
                ],
            ]),
        ]);

        $page = (new SeoContentExtractionService)->extract('https://example.com/page');

        $this->assertSame('firecrawl', $page['source']);
        $this->assertStringContainsString('Giá thu mua chì phế liệu', $page['content']);
        $this->assertStringNotContainsString('Menu item 1', $page['content']);
        $this->assertStringNotContainsString('AddToAny', $page['content']);
        $this->assertTrue($page['metrics']['auditReady'] ?? false);
    }
}
