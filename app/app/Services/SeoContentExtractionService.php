<?php

namespace App\Services;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SeoContentExtractionService
{
    /**
     * @return array<string, mixed>
     */
    public function extract(string $url): array
    {
        $provider = $this->contentProvider();

        if ($provider === 'firecrawl') {
            return $this->extractWithProviderRetries(
                'Firecrawl',
                fn (): array => $this->extractWithFirecrawl($url),
                $url,
                fallback: fn (): array => $this->extractFromHtml($url),
            );
        }

        if ($provider === 'jina') {
            return $this->extractWithProviderRetries(
                'Jina Reader',
                fn (): array => $this->extractWithJina($url),
                $url,
                fallback: fn (): array => $this->extractFromHtml($url),
            );
        }

        return $this->extractFromHtml($url);
    }

    private function contentProvider(): string
    {
        $configured = strtolower(trim((string) config('services.audit.content_provider', '')));

        if (in_array($configured, ['firecrawl', 'jina', 'html'], true)) {
            return $configured;
        }

        if (config('services.audit.use_jina', true)) {
            return 'jina';
        }

        return 'html';
    }

    /**
     * @param  callable(): array<string, mixed>  $extract
     * @param  callable(): array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function extractWithProviderRetries(
        string $providerLabel,
        callable $extract,
        string $url,
        callable $fallback,
    ): array {
        $attempts = max(1, (int) config('services.audit.ai_http_retry_attempts', 3));
        $sleepMs = max(0, (int) config('services.audit.ai_http_retry_sleep_ms', 2000));
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $extract();
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if ($attempt < $attempts && $sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        Log::warning("{$providerLabel} failed, falling back to HTML extraction.", [
            'url' => $url,
            'attempts' => $attempts,
            'error' => $lastException?->getMessage(),
        ]);

        return $fallback();
    }

    /**
     * @return array<string, mixed>
     */
    public function extractOrFallback(string $url): array
    {
        try {
            return $this->extract($url);
        } catch (\Throwable $exception) {
            return [
                'url' => $url,
                'title' => '',
                'metaDescription' => '',
                'canonicalUrl' => '',
                'headings' => [
                    'h1' => [],
                    'h2' => [],
                    'h3' => [],
                ],
                'metrics' => [
                    'wordCount' => 0,
                    'imageCount' => 0,
                    'missingAltCount' => 0,
                    'internalLinkCount' => 0,
                    'externalLinkCount' => 0,
                    'hasCanonical' => false,
                    'titleLength' => 0,
                    'metaDescriptionLength' => 0,
                    'h1Count' => 0,
                ],
                'content' => '',
                'source' => 'url_only',
                'extractionError' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractWithJina(string $url): array
    {
        $readerUrl = rtrim((string) config('services.audit.jina_base_url', 'https://r.jina.ai/'), '/').'/'.$url;
        $headers = [
            'User-Agent' => config('services.audit.user_agent', 'ClickonAuditBot/1.0 (+https://clickon-audit.local)'),
            'Accept' => 'text/plain',
        ];
        $apiKey = config('services.audit.jina_api_key');

        if ($apiKey) {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }

        $response = Http::withHeaders($headers)
            ->timeout(90)
            ->get($readerUrl);

        if (! $response->successful()) {
            throw new RuntimeException("Jina Reader failed for [{$url}] with status {$response->status()}.");
        }

        $text = trim($response->body());

        if ($text === '') {
            throw new RuntimeException("Jina Reader returned empty content for [{$url}].");
        }

        $parsed = $this->parseJinaReaderText($text);
        $title = $parsed['title'];
        $metaDescription = $parsed['metaDescription'];
        $content = $parsed['content'];
        $headings = $this->extractMarkdownHeadings($parsed['markdown']);
        $mediaMetrics = $this->extractMarkdownMediaMetrics($parsed['markdown'], $url);

        if ($headings['h1'] === [] && $title !== '') {
            $headings['h1'] = [$title];
        }

        if ($content === '') {
            throw new RuntimeException("Jina Reader returned no Markdown Content for [{$url}].");
        }

        $page = [
            'url' => $url,
            'title' => $title,
            'metaDescription' => $metaDescription,
            'canonicalUrl' => $url,
            'headings' => $headings,
            'metrics' => [
                'wordCount' => str_word_count(strip_tags($content)),
                'imageCount' => $mediaMetrics['imageCount'],
                'missingAltCount' => $mediaMetrics['missingAltCount'],
                'internalLinkCount' => $mediaMetrics['internalLinkCount'],
                'externalLinkCount' => $mediaMetrics['externalLinkCount'],
                'hasCanonical' => false,
                'titleLength' => mb_strlen($title),
                'metaDescriptionLength' => mb_strlen($metaDescription),
                'h1Count' => count($headings['h1']),
            ],
            'content' => mb_substr($content, 0, (int) config('services.audit.max_content_chars', 18000)),
            'checklistEvidence' => $this->buildChecklistEvidenceFromMarkdown(
                markdown: $parsed['markdown'],
                pageUrl: $url,
                title: $title,
                metaDescription: $metaDescription,
                content: $content,
                headings: $headings,
            ),
            'source' => 'jina',
        ];

        return $this->finalizeExtractedPage($this->supplementPageFromHtml($url, $page));
    }

    /**
     * @return array<string, mixed>
     */
    private function extractWithFirecrawl(string $url): array
    {
        $baseUrl = rtrim((string) config('services.audit.firecrawl_base_url', ''), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('Firecrawl base URL is not configured.');
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        $apiKey = config('services.audit.firecrawl_api_key');

        if ($apiKey) {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }

        $response = Http::withHeaders($headers)
            ->timeout(max(30, (int) config('services.audit.firecrawl_timeout_seconds', 120)))
            ->post("{$baseUrl}/v1/scrape", [
                'url' => $url,
                'formats' => ['markdown', 'html', 'links'],
                'onlyMainContent' => (bool) config('services.audit.firecrawl_only_main_content', true),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Firecrawl scrape failed for [{$url}] with status {$response->status()}.");
        }

        $payload = $response->json();

        if (! is_array($payload) || ! ($payload['success'] ?? false)) {
            $error = is_array($payload) ? (string) ($payload['error'] ?? 'unknown error') : 'invalid response';

            throw new RuntimeException("Firecrawl scrape failed for [{$url}]: {$error}");
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $markdown = trim((string) ($data['markdown'] ?? ''));
        $html = trim((string) ($data['html'] ?? ''));
        $linkUrls = is_array($data['links'] ?? null) ? $data['links'] : [];

        $title = trim((string) ($metadata['title'] ?? $metadata['og:title'] ?? ''));
        $metaDescription = trim((string) ($metadata['description'] ?? $metadata['og:description'] ?? ''));
        $canonicalUrl = trim((string) ($metadata['og:url'] ?? $metadata['ogUrl'] ?? $metadata['sourceURL'] ?? $url));

        if ($canonicalUrl === '') {
            $canonicalUrl = $url;
        }

        $headings = [
            'h1' => [],
            'h2' => [],
            'h3' => [],
        ];
        $imageCount = 0;
        $missingAltCount = 0;
        $internalLinkCount = 0;
        $externalLinkCount = 0;
        $content = '';
        $checklistEvidence = null;

        if ($html !== '') {
            $parsedHtml = $this->parseHtmlDocument($html, $url);
            $headings = $parsedHtml['headings'];

            if ($title === '') {
                $title = $parsedHtml['title'];
            }

            if ($metaDescription === '') {
                $metaDescription = $parsedHtml['metaDescription'];
            }

            if ($parsedHtml['canonicalUrl'] !== '') {
                $canonicalUrl = $parsedHtml['canonicalUrl'];
            }

            $imageCount = $parsedHtml['imageCount'];
            $missingAltCount = $parsedHtml['missingAltCount'];
            $internalLinkCount = $parsedHtml['internalLinkCount'];
            $externalLinkCount = $parsedHtml['externalLinkCount'];
            $content = $parsedHtml['content'];
            $checklistEvidence = $parsedHtml['checklistEvidence'] ?? null;
        }

        if ($markdown !== '') {
            $markdownHeadings = $this->extractMarkdownHeadings($markdown);

            foreach (['h1', 'h2', 'h3'] as $tag) {
                if ($this->shouldPreferMarkdownHeadings($headings[$tag], $markdownHeadings[$tag])) {
                    $headings[$tag] = $markdownHeadings[$tag];
                }
            }

            $markdownMedia = $this->extractMarkdownMediaMetrics($markdown, $url);

            if ($imageCount === 0) {
                $imageCount = $markdownMedia['imageCount'];
                $missingAltCount = $markdownMedia['missingAltCount'];
            }

            $markdownContent = $this->normalizeMarkdownExcerpt($markdown, $title);
            $content = $this->resolveFirecrawlExcerpt($content, $markdownContent, $title);
        }

        if ($html === '' && $linkUrls !== []) {
            [$linksInternal, $linksExternal] = $this->countAbsoluteUrlListLinks($linkUrls, $url);
            $internalLinkCount = max($internalLinkCount, $linksInternal);
            $externalLinkCount = max($externalLinkCount, $linksExternal);
        }

        if ($content === '') {
            throw new RuntimeException("Firecrawl returned no content for [{$url}].");
        }

        if ($headings['h1'] === [] && $title !== '') {
            $headings['h1'] = [$title];
        } elseif ($title !== '' && $headings['h1'] !== [] && $this->headingLooksMojibake((string) ($headings['h1'][0] ?? ''))) {
            $headings['h1'] = [$title];
        }

        if ($checklistEvidence === null && $markdown !== '') {
            $checklistEvidence = $this->buildChecklistEvidenceFromMarkdown(
                markdown: $markdown,
                pageUrl: $url,
                title: $title,
                metaDescription: $metaDescription,
                content: $content,
                headings: $headings,
            );
        }

        return $this->finalizeExtractedPage([
            'url' => $url,
            'title' => $title,
            'metaDescription' => $metaDescription,
            'canonicalUrl' => $canonicalUrl,
            'headings' => $headings,
            'metrics' => [
                'wordCount' => str_word_count(strip_tags($content)),
                'imageCount' => $imageCount,
                'missingAltCount' => $missingAltCount,
                'internalLinkCount' => $internalLinkCount,
                'externalLinkCount' => $externalLinkCount,
                'hasCanonical' => $canonicalUrl !== '',
                'titleLength' => mb_strlen($title),
                'metaDescriptionLength' => mb_strlen($metaDescription),
                'h1Count' => count($headings['h1']),
            ],
            'content' => $content,
            'checklistEvidence' => $checklistEvidence,
            'source' => 'firecrawl',
        ]);
    }

    /**
     * @return array{title: string, metaDescription: string, markdown: string, content: string}
     */
    private function parseJinaReaderText(string $text): array
    {
        $title = '';
        $metaDescription = '';
        $markdown = '';

        if (preg_match('/^Title:\s*(.+)$/mi', $text, $matches)) {
            $title = trim($matches[1]);
        }

        if (preg_match('/^Description:\s*(.+)$/mi', $text, $matches)) {
            $metaDescription = trim($matches[1]);
        }

        if ($metaDescription === '' && preg_match('/^og:description:\s*(.+)$/mi', $text, $matches)) {
            $metaDescription = trim($matches[1]);
        }

        if ($metaDescription === '' && preg_match('/^meta description:\s*(.+)$/mi', $text, $matches)) {
            $metaDescription = trim($matches[1]);
        }

        if (preg_match('/^Markdown Content:\s*\r?\n([\s\S]*)$/mi', $text, $matches)) {
            $markdown = trim($matches[1]);
        }

        $content = $markdown !== ''
            ? $this->buildAuditContentFromMarkdown($markdown)
            : trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return [
            'title' => $title,
            'metaDescription' => $metaDescription,
            'markdown' => $markdown,
            'content' => $content,
        ];
    }

    /**
     * @return array{h1: array<int, string>, h2: array<int, string>, h3: array<int, string>}
     */
    private function extractMarkdownHeadings(string $markdown): array
    {
        $headings = [
            'h1' => [],
            'h2' => [],
            'h3' => [],
        ];

        if ($markdown === '') {
            return $headings;
        }

        foreach (preg_split('/\r?\n/', $markdown) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^#\s+\*?\*?(.+?)\*?\*?\s*$/u', $line, $matches)) {
                $heading = $this->normalizeMarkdownHeading($matches[1]);

                if ($heading !== '') {
                    $headings['h1'][] = $heading;
                }

                continue;
            }

            if (preg_match('/^##\s+\*?\*?(.+?)\*?\*?\s*$/u', $line, $matches)) {
                $heading = $this->normalizeMarkdownHeading($matches[1]);

                if ($heading !== '') {
                    $headings['h2'][] = $heading;
                }

                continue;
            }

            if (preg_match('/^###\s+\*?\*?(.+?)\*?\*?\s*$/u', $line, $matches)) {
                $heading = $this->normalizeMarkdownHeading($matches[1]);

                if ($heading !== '') {
                    $headings['h3'][] = $heading;
                }
            }
        }

        return $headings;
    }

    /**
     * @return array{imageCount: int, missingAltCount: int, internalLinkCount: int, externalLinkCount: int}
     */
    private function extractMarkdownMediaMetrics(string $markdown, string $pageUrl): array
    {
        $imageCount = 0;
        $missingAltCount = 0;
        $internalLinkCount = 0;
        $externalLinkCount = 0;

        if ($markdown === '') {
            return compact('imageCount', 'missingAltCount', 'internalLinkCount', 'externalLinkCount');
        }

        if (preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/u', $markdown, $images, PREG_SET_ORDER)) {
            foreach ($images as $image) {
                $imageCount++;
                $alt = trim((string) ($image[1] ?? ''));

                if ($alt === '' || preg_match('/^image\s+\d+$/i', $alt)) {
                    $missingAltCount++;
                }
            }
        }

        if (preg_match_all('/(?<!!)\[([^\]]*)\]\(([^)]+)\)/u', $markdown, $links, PREG_SET_ORDER)) {
            foreach ($links as $link) {
                $href = trim((string) ($link[2] ?? ''));

                if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                    continue;
                }

                if ($this->isInternalHref($href, $pageUrl)) {
                    $internalLinkCount++;
                } else {
                    $externalLinkCount++;
                }
            }
        }

        return compact('imageCount', 'missingAltCount', 'internalLinkCount', 'externalLinkCount');
    }

    private function isInternalHref(string $href, string $pageUrl): bool
    {
        $targetHost = parse_url($href, PHP_URL_HOST);
        $pageHost = parse_url($pageUrl, PHP_URL_HOST);

        if ($targetHost === null || $targetHost === '') {
            return true;
        }

        if ($pageHost === null || $pageHost === '') {
            return false;
        }

        return strcasecmp((string) $targetHost, (string) $pageHost) === 0;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function supplementPageFromHtml(string $url, array $page): array
    {
        if (! config('services.audit.jina_html_meta_fallback', true)) {
            return $page;
        }

        $needsMeta = trim((string) ($page['metaDescription'] ?? '')) === '';
        $needsTitle = trim((string) ($page['title'] ?? '')) === '';
        $needsCanonical = trim((string) ($page['canonicalUrl'] ?? '')) === ''
            || ($page['canonicalUrl'] ?? null) === $url;

        if (! $needsMeta && ! $needsTitle && ! $needsCanonical) {
            return $page;
        }

        try {
            $metadata = $this->fetchHtmlMetadata($url);
        } catch (\Throwable $exception) {
            Log::warning('HTML metadata fallback failed after Jina extraction.', [
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return $page;
        }

        if ($needsTitle && ($metadata['title'] ?? '') !== '') {
            $page['title'] = $metadata['title'];
            $page['metrics']['titleLength'] = mb_strlen($metadata['title']);
        }

        if ($needsMeta && ($metadata['metaDescription'] ?? '') !== '') {
            $page['metaDescription'] = $metadata['metaDescription'];
            $page['metrics']['metaDescriptionLength'] = mb_strlen($metadata['metaDescription']);
        }

        if (($metadata['canonicalUrl'] ?? '') !== '') {
            $page['canonicalUrl'] = $metadata['canonicalUrl'];
            $page['metrics']['hasCanonical'] = true;
        }

        return $page;
    }

    /**
     * @return array{title: string, metaDescription: string, canonicalUrl: string}
     */
    private function fetchHtmlMetadata(string $url): array
    {
        $response = Http::withHeaders([
            'User-Agent' => config('services.audit.user_agent', 'ClickonAuditBot/1.0 (+https://clickon-audit.local)'),
            'Accept-Language' => 'vi,en;q=0.8',
        ])->timeout(20)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Unable to fetch HTML metadata for [{$url}] with status {$response->status()}.");
        }

        $html = $response->body();
        $dom = new DOMDocument();

        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $title = trim((string) $xpath->evaluate('string(//title)'));
        $metaDescription = $this->extractMeta($xpath, 'description');

        if ($metaDescription === '') {
            $metaDescription = trim((string) $xpath->evaluate(
                'string((//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:description"]/@content)[1])'
            ));
        }

        $canonicalUrl = trim((string) $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));

        return [
            'title' => $title,
            'metaDescription' => $metaDescription,
            'canonicalUrl' => $canonicalUrl,
        ];
    }

    /**
     * @return array{0:int,1:int}
     */
    private function countAbsoluteUrlListLinks(array $urls, string $pageUrl): array
    {
        $internal = 0;
        $external = 0;
        $seen = [];

        foreach ($urls as $href) {
            if (! is_string($href)) {
                continue;
            }

            $href = trim($href);

            if ($href === ''
                || str_starts_with($href, '#')
                || str_starts_with($href, 'javascript:')
                || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'tel:')) {
                continue;
            }

            if (isset($seen[$href])) {
                continue;
            }

            $seen[$href] = true;

            if ($this->isInternalHref($href, $pageUrl)) {
                $internal++;
            } else {
                $external++;
            }
        }

        return [$internal, $external];
    }

    /**
     * @return array{
     *   title: string,
     *   metaDescription: string,
     *   canonicalUrl: string,
     *   headings: array{h1: array<int, string>, h2: array<int, string>, h3: array<int, string>},
     *   content: string,
     *   imageCount: int,
     *   missingAltCount: int,
     *   internalLinkCount: int,
     *   externalLinkCount: int
     * }
     */
    private function parseHtmlDocument(string $html, string $pageUrl): array
    {
        $dom = new DOMDocument();

        libxml_use_internal_errors(true);
        $dom->loadHTML($this->prepareHtmlForDomDocument($html), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $title = trim((string) $xpath->evaluate('string(//title)'));
        $metaDescription = $this->extractMeta($xpath, 'description');

        if ($metaDescription === '') {
            $metaDescription = trim((string) $xpath->evaluate(
                'string((//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:description"]/@content)[1])'
            ));
        }

        $canonicalUrl = trim((string) $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
        $mainNode = $this->resolveMainNode($xpath, $dom);
        $headings = [
            'h1' => $this->extractHeadingsFromNode($xpath, $mainNode, 'h1'),
            'h2' => $this->extractHeadingsFromNode($xpath, $mainNode, 'h2'),
            'h3' => $this->extractHeadingsFromNode($xpath, $mainNode, 'h3'),
        ];

        foreach (['h1', 'h2', 'h3'] as $tag) {
            if ($headings[$tag] === []) {
                $headings[$tag] = $this->extractHeadings($xpath, $tag);
            }
        }

        $contentTitle = $title !== '' ? $title : (string) ($headings['h1'][0] ?? '');
        $content = $this->finalizeAuditContent($this->buildAuditContentFromNode($mainNode), $contentTitle);
        $nodeMetrics = $this->analyzeNodeMetrics($mainNode, $pageUrl);
        $checklistEvidence = $this->buildChecklistEvidence(
            mainNode: $mainNode,
            pageUrl: $pageUrl,
            title: $title,
            metaDescription: $metaDescription,
            content: $content,
            headings: $headings,
        );

        return [
            'title' => $title,
            'metaDescription' => $metaDescription,
            'canonicalUrl' => $canonicalUrl,
            'headings' => $headings,
            'content' => $content,
            'imageCount' => $nodeMetrics['imageCount'],
            'missingAltCount' => $nodeMetrics['missingAltCount'],
            'internalLinkCount' => $nodeMetrics['internalLinkCount'],
            'externalLinkCount' => $nodeMetrics['externalLinkCount'],
            'checklistEvidence' => $checklistEvidence,
        ];
    }

    private function resolveFirecrawlExcerpt(string $htmlContent, string $markdownContent, string $title = ''): string
    {
        $htmlContent = trim($htmlContent);
        $markdownContent = trim($markdownContent);
        $minHtmlChars = max(200, (int) config('services.audit.firecrawl_min_html_content_chars', 500));

        if (mb_strlen($htmlContent) >= $minHtmlChars) {
            return $htmlContent;
        }

        if ($markdownContent !== '' && mb_strlen($markdownContent) >= mb_strlen($htmlContent)) {
            return $this->finalizeAuditContent($markdownContent, $title);
        }

        return $htmlContent !== '' ? $htmlContent : $this->finalizeAuditContent($markdownContent, $title);
    }

    private function normalizeMarkdownExcerpt(string $markdown, string $title): string
    {
        $markdown = $this->buildAuditContentFromMarkdown($markdown);

        if ($markdown === '') {
            return '';
        }

        if ($title !== '') {
            $position = mb_stripos($markdown, $title);

            if ($position !== false && $position < 6000) {
                $markdown = mb_substr($markdown, $position);
            }
        }

        return $this->finalizeAuditContent($markdown, $title);
    }

    private function prepareHtmlForDomDocument(string $html): string
    {
        if (preg_match('/<\?xml[^>]*encoding=/i', $html)) {
            return $html;
        }

        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*utf-8/i', $html)) {
            return '<?xml encoding="UTF-8">'.$html;
        }

        return '<?xml encoding="UTF-8">'.$html;
    }

    /**
     * @param  array<int, string>  $htmlHeadings
     * @param  array<int, string>  $markdownHeadings
     */
    private function shouldPreferMarkdownHeadings(array $htmlHeadings, array $markdownHeadings): bool
    {
        if ($markdownHeadings === []) {
            return false;
        }

        if ($htmlHeadings === []) {
            return true;
        }

        if ($this->headingLooksMojibake((string) ($htmlHeadings[0] ?? ''))) {
            return true;
        }

        return false;
    }

    private function headingLooksMojibake(string $heading): bool
    {
        if ($heading === '') {
            return false;
        }

        return str_contains($heading, 'Ã')
            || str_contains($heading, 'Â')
            || str_contains($heading, 'áº')
            || str_contains($heading, 'á»');
    }

    private function normalizeMarkdownHeading(string $heading): string
    {
        $heading = trim($heading);

        if ($heading === '' || preg_match('/^!\[/u', $heading)) {
            return '';
        }

        $heading = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $heading) ?? $heading;

        return trim($heading, " \t*");
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFromHtml(string $url): array
    {
        $response = Http::withHeaders([
            'User-Agent' => config('services.audit.user_agent', 'ClickonAuditBot/1.0 (+https://clickon-audit.local)'),
            'Accept-Language' => 'vi,en;q=0.8',
        ])->timeout(45)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Unable to fetch URL [{$url}] with status {$response->status()}.");
        }

        $parsed = $this->parseHtmlDocument($response->body(), $url);

        if (mb_strlen($parsed['content']) < 200) {
            Log::warning('HTML extraction returned thin content.', [
                'url' => $url,
                'content_length' => mb_strlen($parsed['content']),
                'content_preview' => mb_substr($parsed['content'], 0, 120),
            ]);
        }

        return $this->finalizeExtractedPage([
            'url' => $url,
            'title' => $parsed['title'],
            'metaDescription' => $parsed['metaDescription'],
            'canonicalUrl' => $parsed['canonicalUrl'] !== '' ? $parsed['canonicalUrl'] : $url,
            'headings' => $parsed['headings'],
            'metrics' => [
                'wordCount' => str_word_count(strip_tags($parsed['content'])),
                'imageCount' => $parsed['imageCount'],
                'missingAltCount' => $parsed['missingAltCount'],
                'internalLinkCount' => $parsed['internalLinkCount'],
                'externalLinkCount' => $parsed['externalLinkCount'],
                'hasCanonical' => $parsed['canonicalUrl'] !== '',
                'titleLength' => mb_strlen($parsed['title']),
                'metaDescriptionLength' => mb_strlen($parsed['metaDescription']),
                'h1Count' => count($parsed['headings']['h1']),
            ],
            'content' => $parsed['content'],
            'checklistEvidence' => $parsed['checklistEvidence'] ?? null,
            'source' => 'html',
        ]);
    }

    private function extractMeta(DOMXPath $xpath, string $name): string
    {
        return trim((string) $xpath->evaluate(sprintf(
            'string((//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]/@content)[1])',
            strtolower($name)
        )));
    }

    /**
     * @return array<int, string>
     */
    private function extractHeadings(DOMXPath $xpath, string $tag): array
    {
        $nodes = $xpath->query("//{$tag}");

        if (! $nodes) {
            return [];
        }

        $headings = [];

        foreach ($nodes as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

            if ($text !== '') {
                $headings[] = $text;
            }
        }

        return $headings;
    }

    private function resolveMainNode(DOMXPath $xpath, DOMDocument $dom): DOMNode
    {
        $minimumChars = 200;
        $queries = [
            '//article',
            '//main',
            '//*[@role="main"]',
            '//*[contains(@class, "entry-content")]',
            '//*[contains(@class, "post-content")]',
            '//*[contains(@class, "article-content")]',
            '//*[contains(@class, "content-main")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " content ")]',
        ];

        $bestNode = null;
        $bestLength = 0;

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if (! $nodes) {
                continue;
            }

            for ($index = 0; $index < $nodes->count(); $index++) {
                $node = $nodes->item($index);

                if (! $node instanceof DOMNode) {
                    continue;
                }

                $text = trim(preg_replace('/\s+/u', ' ', $this->extractReadableText($node)) ?? '');
                $length = mb_strlen($text);

                if ($length > $bestLength) {
                    $bestLength = $length;
                    $bestNode = $node;
                }
            }
        }

        if ($bestNode instanceof DOMNode && ($bestLength >= $minimumChars || $bestLength > 0)) {
            return $bestNode;
        }

        return $dom->getElementsByTagName('body')->item(0) ?? $dom;
    }

    private function extractReadableText(DOMNode $node): string
    {
        $clone = $node->cloneNode(true);
        $this->removeNoise($clone);

        return trim($clone->textContent);
    }

    private function removeNoise(DOMNode $node): void
    {
        $blockedTags = ['script', 'style', 'noscript', 'nav', 'footer', 'header', 'aside', 'form', 'svg'];

        if ($node->hasChildNodes()) {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof DOMComment) {
                    $node->removeChild($child);
                    continue;
                }

                if ($child instanceof DOMElement) {
                    if (in_array(strtolower($child->tagName), $blockedTags, true) || $this->shouldRemoveElement($child)) {
                        $node->removeChild($child);
                        continue;
                    }
                }

                $this->removeNoise($child);
            }
        }
    }

    private function shouldRemoveElement(DOMElement $element): bool
    {
        $role = strtolower(trim((string) $element->getAttribute('role')));

        if (in_array($role, ['navigation', 'complementary', 'banner', 'contentinfo'], true)) {
            return true;
        }

        if (strtolower(trim((string) $element->getAttribute('aria-hidden'))) === 'true') {
            return true;
        }

        $haystack = strtolower(trim((string) $element->getAttribute('class').' '.(string) $element->getAttribute('id')));
        $patterns = [
            'breadcrumb',
            'menu',
            'sidebar',
            'widget',
            'related',
            'addtoany',
            'a2a',
            'share',
            'social',
            'hotline',
            'danhmuc',
            'category-list',
            'recent-post',
            'recentpost',
            'comment',
            'subscribe',
            'newsletter',
            'popup',
            'modal',
            'advert',
            'banner',
            'pagination',
            'pager',
            'tag-cloud',
            'author-box',
            'copy-link',
            'wrap-content',
        ];

        foreach ($patterns as $pattern) {
            if ($haystack !== '' && str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function buildAuditContentFromNode(DOMNode $node): string
    {
        $clone = $node->cloneNode(true);
        $this->removeNoise($clone);

        $parts = [];
        $this->collectAuditTextParts($clone, $parts);

        return trim(implode("\n\n", array_filter($parts, fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function collectAuditTextParts(DOMNode $node, array &$parts): void
    {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);

            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'p', 'li', 'blockquote', 'td'], true)) {
                $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

                if ($text !== '') {
                    $prefix = match ($tag) {
                        'h1' => '# ',
                        'h2' => '## ',
                        'h3', 'h4' => '### ',
                        default => '',
                    };
                    $parts[] = $prefix.$text;
                }

                return;
            }
        }

        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                $this->collectAuditTextParts($child, $parts);
            }
        }
    }

    private function buildAuditContentFromMarkdown(string $markdown): string
    {
        $markdown = trim($markdown);

        if ($markdown === '') {
            return '';
        }

        $parts = [];

        foreach (preg_split('/\R+/u', $markdown) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || preg_match('/^!\[/u', $line)) {
                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.+)$/u', $line, $matches)) {
                $level = strlen($matches[1]);
                $heading = $this->normalizeMarkdownHeading($matches[2]);

                if ($heading === '') {
                    continue;
                }

                $prefix = match ($level) {
                    1 => '# ',
                    2 => '## ',
                    default => '### ',
                };
                $parts[] = $prefix.$heading;

                continue;
            }

            if (preg_match('/^(Copy link|AddToAny|\* Menu|Find any service)/iu', $line)) {
                break;
            }

            $line = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $line) ?? $line;
            $line = trim(preg_replace('/[ \t]+/u', ' ', $line) ?? '');

            if ($line !== '') {
                $parts[] = $line;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    private function finalizeAuditContent(string $content, string $title): string
    {
        $content = trim($content);

        if ($content === '') {
            return '';
        }

        if ($title !== '') {
            $position = mb_stripos($content, $title);

            if ($position !== false && $position > 0 && $position < 1500) {
                $content = mb_substr($content, $position);
            }
        }

        $stopMarkers = [
            'Danh mục thu mua',
            'Bài viết mới nhất',
            'Bài viết liên quan',
            'Copy link',
            'AddToAny',
            'Find any service',
            'Chia sẻ bài viết',
            'Tags:',
            'Thẻ:',
        ];

        foreach ($stopMarkers as $marker) {
            $position = mb_stripos($content, $marker);

            if ($position !== false && $position >= (int) (mb_strlen($content) * 0.35)) {
                $content = mb_substr($content, 0, $position);
            }
        }

        return trim($content);
    }

    /**
     * @return array<string, mixed>
     */
    private function finalizeExtractedPage(array $page): array
    {
        $title = trim((string) ($page['title'] ?? ''));
        $content = $this->finalizeAuditContent(trim((string) ($page['content'] ?? '')), $title);
        $wordCount = $this->countAuditWords($content);
        $minWords = max(50, (int) config('services.audit.min_audit_content_words', 80));
        $minChars = max(200, (int) config('services.audit.min_audit_content_chars', 500));
        $maxChars = (int) config('services.audit.max_content_chars', 18000);
        $issues = [];

        if ($wordCount < $minWords) {
            $issues[] = 'thin_content';
        }

        if (mb_strlen($content) < $minChars) {
            $issues[] = 'short_excerpt';
        }

        $metrics = is_array($page['metrics'] ?? null) ? $page['metrics'] : [];
        $metrics['wordCount'] = $wordCount;
        $metrics['auditContentChars'] = mb_strlen($content);
        $metrics['auditReady'] = $wordCount >= $minWords && mb_strlen($content) >= $minChars;
        $metrics['contentQualityIssues'] = $issues;

        if (is_array($page['checklistEvidence'] ?? null)) {
            $page['checklistEvidence']['content'] = $this->analyzeContentStructure($content);
            $page['checklistEvidence']['verifiableCriteria'] = $this->resolveVerifiableCriteria($page['checklistEvidence']);
            $metrics['checklistEvidence'] = $page['checklistEvidence'];
        }

        $page['content'] = mb_substr($content, 0, $maxChars);
        $page['metrics'] = $metrics;

        return $page;
    }

    private function countAuditWords(string $content): int
    {
        $plain = trim(preg_replace('/[#*\[\]()>`_\-]+/u', ' ', strip_tags($content)) ?? '');

        if ($plain === '') {
            return 0;
        }

        $tokens = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($tokens) ? count($tokens) : 0;
    }

    /**
     * @return array{imageCount: int, missingAltCount: int, internalLinkCount: int, externalLinkCount: int}
     */
    private function analyzeNodeMetrics(DOMNode $node, string $pageUrl): array
    {
        $xpath = new DOMXPath($node->ownerDocument ?? new DOMDocument());
        $images = $xpath->query('.//img', $node);
        $links = $xpath->query('.//a[@href]', $node);
        [$internalLinks, $externalLinks] = $this->countLinks($links, $pageUrl);
        $missingAltCount = 0;

        foreach ($images ?: [] as $image) {
            if ($image instanceof DOMElement && trim((string) $image->getAttribute('alt')) === '') {
                $missingAltCount++;
            }
        }

        return [
            'imageCount' => $images?->count() ?? 0,
            'missingAltCount' => $missingAltCount,
            'internalLinkCount' => $internalLinks,
            'externalLinkCount' => $externalLinks,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractHeadingsFromNode(DOMXPath $xpath, DOMNode $context, string $tag): array
    {
        $nodes = $xpath->query(".//{$tag}", $context);

        if (! $nodes) {
            return [];
        }

        $headings = [];

        foreach ($nodes as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

            if ($text !== '') {
                $headings[] = $text;
            }
        }

        return $headings;
    }

    /**
     * @param  array{h1: array<int, string>, h2: array<int, string>, h3: array<int, string>}  $headings
     * @return array<string, mixed>
     */
    private function buildChecklistEvidence(
        ?DOMNode $mainNode,
        string $pageUrl,
        string $title,
        string $metaDescription,
        string $content,
        array $headings,
    ): array {
        $evidence = $this->buildChecklistEvidenceBase($pageUrl, $title, $metaDescription, $content, $headings);

        if ($mainNode instanceof DOMNode) {
            $xpath = new DOMXPath($mainNode->ownerDocument ?? new DOMDocument());
            $siteRoot = $this->siteRootUrl($pageUrl);
            $evidence['images'] = $this->extractImageEvidence($xpath, $mainNode);
            $evidence['internalLinks'] = $this->extractInternalLinkEvidence($xpath, $mainNode, $pageUrl);
            $evidence['structure'] = $this->extractStructureFlags($xpath, $mainNode);
            $evidence['sapo'] = $this->extractSapoEvidence($xpath, $mainNode, $pageUrl, $siteRoot);
            $evidence['cta'] = $this->detectCtaSignals($xpath, $mainNode);
            $evidence['faq'] = $this->detectFaqSignals($xpath, $mainNode, $headings);
        }

        $evidence['verifiableCriteria'] = $this->resolveVerifiableCriteria($evidence);

        return $evidence;
    }

    /**
     * @param  array{h1: array<int, string>, h2: array<int, string>, h3: array<int, string>}  $headings
     * @return array<string, mixed>
     */
    private function buildChecklistEvidenceFromMarkdown(
        string $markdown,
        string $pageUrl,
        string $title,
        string $metaDescription,
        string $content,
        array $headings,
    ): array {
        $evidence = $this->buildChecklistEvidenceBase($pageUrl, $title, $metaDescription, $content, $headings);
        $images = [];
        $links = [];

        if ($markdown !== '') {
            if (preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/u', $markdown, $imageMatches, PREG_SET_ORDER)) {
                foreach ($imageMatches as $imageMatch) {
                    $src = trim((string) ($imageMatch[2] ?? ''));
                    $alt = trim((string) ($imageMatch[1] ?? ''));
                    $images[] = [
                        'src' => $src,
                        'fileName' => $this->extractFileNameFromUrl($src),
                        'alt' => $alt,
                        'hasAlt' => $alt !== '' && ! preg_match('/^image\s+\d+$/i', $alt),
                    ];
                }
            }

            if (preg_match_all('/(?<!!)\[([^\]]*)\]\(([^)]+)\)/u', $markdown, $linkMatches, PREG_SET_ORDER)) {
                foreach ($linkMatches as $linkMatch) {
                    $href = trim((string) ($linkMatch[2] ?? ''));
                    $anchor = trim((string) ($linkMatch[1] ?? ''));

                    if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                        continue;
                    }

                    if (! $this->isInternalHref($href, $pageUrl)) {
                        continue;
                    }

                    $links[] = [
                        'href' => $href,
                        'anchor' => $anchor,
                        'normalizedAnchor' => mb_strtolower($anchor),
                    ];
                }
            }
        }

        $evidence['images'] = [
            'count' => count($images),
            'missingAltCount' => count(array_filter($images, fn (array $item): bool => ! ($item['hasAlt'] ?? false))),
            'items' => array_slice($images, 0, 20),
        ];
        $evidence['internalLinks'] = $this->finalizeInternalLinkEvidence($links);
        $evidence['structure'] = $this->extractStructureFlagsFromMarkdown($markdown);
        $evidence['sapo'] = $this->extractSapoEvidenceFromContent($content, $pageUrl);
        $evidence['cta'] = $this->detectCtaSignalsFromText($content);
        $evidence['faq'] = $this->detectFaqSignalsFromHeadings($headings, $content);
        $evidence['verifiableCriteria'] = $this->resolveVerifiableCriteria($evidence);

        return $evidence;
    }

    /**
     * @param  array{h1: array<int, string>, h2: array<int, string>, h3: array<int, string>}  $headings
     * @return array<string, mixed>
     */
    private function buildChecklistEvidenceBase(
        string $pageUrl,
        string $title,
        string $metaDescription,
        string $content,
        array $headings,
    ): array {
        $slug = $this->extractUrlSlug($pageUrl);

        return [
            'source' => 'step1_extraction',
            'checklistVersion' => 'Checklist Audit SEO.pdf',
            'title' => [
                'text' => $title,
                'length' => mb_strlen($title),
                'hasNumber' => (bool) preg_match('/\d/u', $title),
                'targetRange' => '50-60',
            ],
            'metaDescription' => [
                'text' => $metaDescription,
                'length' => mb_strlen($metaDescription),
                'targetRange' => '120-150',
            ],
            'url' => [
                'full' => $pageUrl,
                'slug' => $slug,
                'length' => mb_strlen($slug),
                'usesHyphens' => str_contains($slug, '-'),
                'hasDiacritics' => $this->stringHasVietnameseDiacritics($slug),
            ],
            'headings' => [
                'h1' => $headings['h1'] ?? [],
                'h2' => $headings['h2'] ?? [],
                'h3' => $headings['h3'] ?? [],
                'h2Count' => count($headings['h2'] ?? []),
                'h3Count' => count($headings['h3'] ?? []),
            ],
            'content' => $this->analyzeContentStructure($content),
            'images' => ['count' => 0, 'missingAltCount' => 0, 'items' => []],
            'internalLinks' => ['count' => 0, 'items' => [], 'duplicateAnchors' => []],
            'structure' => [
                'hasBulletList' => false,
                'hasNumberedList' => false,
                'hasTable' => false,
            ],
            'sapo' => [
                'text' => null,
                'hasSelfLink' => false,
                'hasHomepageLink' => false,
                'links' => [],
            ],
            'cta' => ['detected' => false, 'signals' => []],
            'faq' => ['detected' => false, 'signals' => []],
            'externalDataRequired' => [5, 6, 19, 22, 23],
            'verifiableCriteria' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeContentStructure(string $content): array
    {
        $paragraphs = array_values(array_filter(array_map(
            static fn (string $paragraph): string => trim($paragraph),
            preg_split('/\n\n+/u', $content) ?: [],
        ), static fn (string $paragraph): bool => $paragraph !== '' && ! str_starts_with($paragraph, '#')));

        $longCount = 0;
        $shortCount = 0;

        foreach ($paragraphs as $paragraph) {
            $words = $this->countAuditWords($paragraph);

            if ($words > 70) {
                $longCount++;
            } elseif ($words > 0) {
                $shortCount++;
            }
        }

        $paragraphCount = count($paragraphs);
        $wordCount = $this->countAuditWords($content);

        return [
            'wordCount' => $wordCount,
            'targetWordRange' => '1000-3000',
            'paragraphCount' => $paragraphCount,
            'longParagraphCount' => $longCount,
            'shortParagraphCount' => $shortCount,
            'shortParagraphRatio' => $paragraphCount > 0 ? round($shortCount / $paragraphCount, 2) : 0,
            'targetShortParagraphRatio' => 0.30,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractImageEvidence(DOMXPath $xpath, DOMNode $mainNode): array
    {
        $images = $xpath->query('.//img', $mainNode);
        $items = [];
        $missingAltCount = 0;

        foreach ($images ?: [] as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            $src = trim((string) $image->getAttribute('src'));
            $alt = trim((string) $image->getAttribute('alt'));
            $hasAlt = $alt !== '' && ! preg_match('/^image\s+\d+$/i', $alt);

            if (! $hasAlt) {
                $missingAltCount++;
            }

            $items[] = [
                'src' => $src,
                'fileName' => $this->extractFileNameFromUrl($src),
                'alt' => $alt,
                'hasAlt' => $hasAlt,
            ];
        }

        return [
            'count' => count($items),
            'missingAltCount' => $missingAltCount,
            'items' => array_slice($items, 0, 20),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractInternalLinkEvidence(DOMXPath $xpath, DOMNode $mainNode, string $pageUrl): array
    {
        $links = $xpath->query('.//a[@href]', $mainNode);
        $items = [];

        foreach ($links ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $href = trim((string) $link->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                continue;
            }

            if (! $this->isInternalHref($href, $pageUrl)) {
                continue;
            }

            $anchor = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');

            if ($anchor === '') {
                continue;
            }

            $items[] = [
                'href' => $href,
                'anchor' => $anchor,
                'normalizedAnchor' => mb_strtolower($anchor),
            ];
        }

        return $this->finalizeInternalLinkEvidence($items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function finalizeInternalLinkEvidence(array $items): array
    {
        $items = array_slice($items, 0, 30);
        $anchorCounts = [];

        foreach ($items as $item) {
            $anchor = (string) ($item['normalizedAnchor'] ?? '');

            if ($anchor === '') {
                continue;
            }

            $anchorCounts[$anchor] = ($anchorCounts[$anchor] ?? 0) + 1;
        }

        $duplicateAnchors = [];

        foreach ($anchorCounts as $anchor => $count) {
            if ($count >= 2) {
                $duplicateAnchors[] = $anchor;
            }
        }

        return [
            'count' => count($items),
            'items' => $items,
            'duplicateAnchors' => $duplicateAnchors,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function extractStructureFlags(DOMXPath $xpath, DOMNode $mainNode): array
    {
        return [
            'hasBulletList' => ($xpath->query('.//ul/li', $mainNode)?->length ?? 0) > 0,
            'hasNumberedList' => ($xpath->query('.//ol/li', $mainNode)?->length ?? 0) > 0,
            'hasTable' => ($xpath->query('.//table', $mainNode)?->length ?? 0) > 0,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function extractStructureFlagsFromMarkdown(string $markdown): array
    {
        return [
            'hasBulletList' => (bool) preg_match('/^\s*[\-*]\s+/m', $markdown),
            'hasNumberedList' => (bool) preg_match('/^\s*\d+\.\s+/m', $markdown),
            'hasTable' => str_contains($markdown, '|') && (bool) preg_match('/\|[^\n]+\|/m', $markdown),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSapoEvidence(DOMXPath $xpath, DOMNode $mainNode, string $pageUrl, string $siteRoot): array
    {
        $paragraphs = $xpath->query('.//p', $mainNode);
        $sapoText = '';
        $links = [];

        foreach ($paragraphs ?: [] as $paragraph) {
            if (! $paragraph instanceof DOMElement) {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $paragraph->textContent) ?? '');

            if (mb_strlen($text) < 40) {
                continue;
            }

            $sapoText = $text;
            $linkNodes = $xpath->query('.//a[@href]', $paragraph);

            foreach ($linkNodes ?: [] as $linkNode) {
                if ($linkNode instanceof DOMElement) {
                    $links[] = $this->normalizeLinkEvidence($linkNode, $pageUrl, $siteRoot);
                }
            }

            break;
        }

        return $this->finalizeSapoEvidence($sapoText, $links, $pageUrl, $siteRoot);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSapoEvidenceFromContent(string $content, string $pageUrl): array
    {
        $siteRoot = $this->siteRootUrl($pageUrl);
        $paragraphs = array_values(array_filter(array_map(
            static fn (string $paragraph): string => trim($paragraph),
            preg_split('/\n\n+/u', $content) ?: [],
        ), static fn (string $paragraph): bool => $paragraph !== '' && ! str_starts_with($paragraph, '#')));

        $sapoText = (string) ($paragraphs[0] ?? '');

        return $this->finalizeSapoEvidence($sapoText, [], $pageUrl, $siteRoot);
    }

    /**
     * @param  array<int, array<string, mixed>>  $links
     * @return array<string, mixed>
     */
    private function finalizeSapoEvidence(string $sapoText, array $links, string $pageUrl, string $siteRoot): array
    {
        $hasSelfLink = false;
        $hasHomepageLink = false;

        foreach ($links as $link) {
            if (($link['isSelf'] ?? false) === true) {
                $hasSelfLink = true;
            }

            if (($link['isHomepage'] ?? false) === true) {
                $hasHomepageLink = true;
            }
        }

        return [
            'text' => $sapoText !== '' ? mb_substr($sapoText, 0, 500) : null,
            'hasSelfLink' => $hasSelfLink,
            'hasHomepageLink' => $hasHomepageLink,
            'links' => $links,
            'note' => $links === [] ? 'Không trích được link trong sapo từ markdown/text-only extraction.' : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeLinkEvidence(DOMElement $link, string $pageUrl, string $siteRoot): array
    {
        $href = trim((string) $link->getAttribute('href'));
        $absoluteHref = $this->resolveAbsoluteUrl($href, $pageUrl);
        $anchor = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');

        return [
            'href' => $absoluteHref,
            'anchor' => $anchor,
            'isInternal' => $this->isInternalHref($href, $pageUrl),
            'isSelf' => $this->urlsMatch($absoluteHref, $pageUrl),
            'isHomepage' => $this->urlsMatch($absoluteHref, $siteRoot),
        ];
    }

    /**
     * @return array{detected: bool, signals: array<int, string>}
     */
    private function detectCtaSignals(DOMXPath $xpath, DOMNode $mainNode): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $mainNode->textContent) ?? '');

        return $this->detectCtaSignalsFromText($text);
    }

    /**
     * @return array{detected: bool, signals: array<int, string>}
     */
    private function detectCtaSignalsFromText(string $text): array
    {
        $patterns = [
            'liên hệ' => '/liên hệ/iu',
            'gọi ngay' => '/gọi ngay/iu',
            'nhận báo giá' => '/nhận báo giá/iu',
            'đặt hàng' => '/đặt hàng/iu',
            'hotline' => '/hotline/iu',
            'tel link' => '/tel:/iu',
        ];
        $signals = [];

        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $text)) {
                $signals[] = $label;
            }
        }

        return [
            'detected' => $signals !== [],
            'signals' => $signals,
        ];
    }

    /**
     * @param  array{h1: array<int, string>, h2: array<int, string>, h3: array<int, string>}  $headings
     * @return array{detected: bool, signals: array<int, string>}
     */
    private function detectFaqSignals(DOMXPath $xpath, DOMNode $mainNode, array $headings): array
    {
        $signals = [];
        $faqPattern = '/faq|q\s*&\s*a|câu hỏi|hỏi đáp/iu';

        foreach (['h2', 'h3'] as $tag) {
            $nodes = $xpath->query(".//{$tag}", $mainNode);

            foreach ($nodes ?: [] as $node) {
                $text = trim((string) $node->textContent);

                if ($text !== '' && preg_match($faqPattern, $text)) {
                    $signals[] = $text;
                }
            }
        }

        if (($xpath->query('.//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "faq")]', $mainNode)?->length ?? 0) > 0) {
            $signals[] = 'faq-class-detected';
        }

        return $this->detectFaqSignalsFromHeadings($headings, implode("\n", $signals));
    }

    /**
     * @param  array{h1: array<int, string>, h2: array<int, string>, h3: array<int, string>}  $headings
     * @return array{detected: bool, signals: array<int, string>}
     */
    private function detectFaqSignalsFromHeadings(array $headings, string $content): array
    {
        $signals = [];
        $faqPattern = '/faq|q\s*&\s*a|câu hỏi|hỏi đáp/iu';

        foreach (['h2', 'h3'] as $tag) {
            foreach ($headings[$tag] ?? [] as $heading) {
                if (preg_match($faqPattern, $heading)) {
                    $signals[] = $heading;
                }
            }
        }

        if ($content !== '' && preg_match($faqPattern, $content)) {
            $signals[] = 'faq-text-detected';
        }

        return [
            'detected' => $signals !== [],
            'signals' => array_values(array_unique($signals)),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<int, int>
     */
    private function resolveVerifiableCriteria(array $evidence): array
    {
        $scorable = [];

        if ((int) ($evidence['title']['length'] ?? 0) > 0) {
            $scorable[] = 1;
        }

        if ((int) ($evidence['metaDescription']['length'] ?? 0) > 0) {
            $scorable[] = 2;
        }

        if (trim((string) ($evidence['sapo']['text'] ?? '')) !== '') {
            $scorable[] = 3;
        }

        if (trim((string) ($evidence['url']['slug'] ?? '')) !== '') {
            $scorable[] = 4;
        }

        if ((int) ($evidence['content']['wordCount'] ?? 0) > 0) {
            $scorable[] = 7;
            $scorable[] = 8;
            $scorable[] = 10;
            $scorable[] = 11;
            $scorable[] = 20;
            $scorable[] = 21;
            $scorable[] = 23;
        }

        if ((int) ($evidence['headings']['h2Count'] ?? 0) > 0 || (int) ($evidence['headings']['h3Count'] ?? 0) > 0) {
            $scorable[] = 9;
            $scorable[] = 22;
        }

        if ((int) ($evidence['images']['count'] ?? 0) >= 0) {
            $scorable[] = 12;
        }

        if (($evidence['images']['items'] ?? []) !== []) {
            $scorable[] = 13;
            $scorable[] = 14;
        }

        if ((int) ($evidence['internalLinks']['count'] ?? 0) > 0) {
            $scorable[] = 15;
            $scorable[] = 16;
        }

        $scorable[] = 17;

        if (($evidence['cta']['detected'] ?? false) === true) {
            $scorable[] = 24;
        }

        if (($evidence['faq']['detected'] ?? false) === true) {
            $scorable[] = 25;
        }

        return array_values(array_unique($scorable));
    }

    private function extractUrlSlug(string $pageUrl): string
    {
        $path = trim((string) parse_url($pageUrl, PHP_URL_PATH), '/');

        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);

        return urldecode((string) end($segments));
    }

    private function extractFileNameFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $fileName = basename($path);

        return urldecode($fileName !== '' && $fileName !== '/' ? $fileName : '');
    }

    private function siteRootUrl(string $pageUrl): string
    {
        $scheme = (string) parse_url($pageUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($pageUrl, PHP_URL_HOST);

        if ($scheme === '' || $host === '') {
            return $pageUrl;
        }

        return $scheme.'://'.$host.'/';
    }

    private function resolveAbsoluteUrl(string $href, string $pageUrl): string
    {
        if ($href === '') {
            return '';
        }

        if (parse_url($href, PHP_URL_SCHEME) !== null) {
            return $href;
        }

        $scheme = (string) parse_url($pageUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($pageUrl, PHP_URL_HOST);

        if ($host === '') {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            return ($scheme !== '' ? $scheme : 'https').':'.$href;
        }

        if (str_starts_with($href, '/')) {
            return ($scheme !== '' ? $scheme : 'https').'://'.$host.$href;
        }

        $basePath = (string) parse_url($pageUrl, PHP_URL_PATH);
        $directory = str_contains($basePath, '/') ? substr($basePath, 0, (int) strrpos($basePath, '/')) : '';

        return ($scheme !== '' ? $scheme : 'https').'://'.$host.$directory.'/'.$href;
    }

    private function urlsMatch(string $left, string $right): bool
    {
        return rtrim($left, '/').'/' === rtrim($right, '/').'/';
    }

    private function stringHasVietnameseDiacritics(string $value): bool
    {
        return (bool) preg_match('/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu', $value);
    }

    /**
     * @param  \DOMNodeList<DOMNode>|false  $links
     * @return array{0:int,1:int}
     */
    private function countLinks($links, string $url): array
    {
        if (! $links) {
            return [0, 0];
        }

        $host = parse_url($url, PHP_URL_HOST);
        $internal = 0;
        $external = 0;

        foreach ($links as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $href = trim((string) $link->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            $targetHost = parse_url($href, PHP_URL_HOST);

            if ($targetHost === null || $targetHost === $host) {
                $internal++;
            } else {
                $external++;
            }
        }

        return [$internal, $external];
    }
}
