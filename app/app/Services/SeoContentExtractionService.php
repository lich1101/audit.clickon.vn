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
        if (config('services.audit.use_jina', true)) {
            $attempts = max(1, (int) config('services.audit.ai_http_retry_attempts', 3));
            $sleepMs = max(0, (int) config('services.audit.ai_http_retry_sleep_ms', 2000));
            $lastException = null;

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    return $this->extractWithJina($url);
                } catch (\Throwable $exception) {
                    $lastException = $exception;

                    if ($attempt < $attempts && $sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }
            }

            Log::warning('Jina Reader failed, falling back to HTML extraction.', [
                'url' => $url,
                'attempts' => $attempts,
                'error' => $lastException?->getMessage(),
            ]);
        }

        return $this->extractFromHtml($url);
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

        if ($headings['h1'] === [] && $title !== '') {
            $headings['h1'] = [$title];
        }

        if ($content === '') {
            throw new RuntimeException("Jina Reader returned no Markdown Content for [{$url}].");
        }

        return [
            'url' => $url,
            'title' => $title,
            'metaDescription' => $metaDescription,
            'canonicalUrl' => $url,
            'headings' => $headings,
            'metrics' => [
                'wordCount' => str_word_count(strip_tags($content)),
                'imageCount' => 0,
                'missingAltCount' => 0,
                'internalLinkCount' => 0,
                'externalLinkCount' => 0,
                'hasCanonical' => false,
                'titleLength' => mb_strlen($title),
                'metaDescriptionLength' => mb_strlen($metaDescription),
                'h1Count' => count($headings['h1']),
            ],
            'content' => mb_substr($content, 0, (int) config('services.audit.max_content_chars', 18000)),
            'source' => 'jina',
        ];
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

        if (preg_match('/^Markdown Content:\s*\r?\n([\s\S]*)$/mi', $text, $matches)) {
            $markdown = trim($matches[1]);
        }

        $content = $markdown !== ''
            ? trim(preg_replace('/\s+/u', ' ', $markdown) ?? '')
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
                $headings['h1'][] = $this->normalizeMarkdownHeading($matches[1]);
                continue;
            }

            if (preg_match('/^##\s+\*?\*?(.+?)\*?\*?\s*$/u', $line, $matches)) {
                $headings['h2'][] = $this->normalizeMarkdownHeading($matches[1]);
                continue;
            }

            if (preg_match('/^###\s+\*?\*?(.+?)\*?\*?\s*$/u', $line, $matches)) {
                $headings['h3'][] = $this->normalizeMarkdownHeading($matches[1]);
            }
        }

        return $headings;
    }

    private function normalizeMarkdownHeading(string $heading): string
    {
        $heading = trim($heading);
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

        $html = $response->body();
        $dom = new DOMDocument();

        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $title = trim((string) $xpath->evaluate('string(//title)'));
        $metaDescription = $this->extractMeta($xpath, 'description');
        $canonicalUrl = trim((string) $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
        $h1 = $this->extractHeadings($xpath, 'h1');
        $h2 = $this->extractHeadings($xpath, 'h2');
        $h3 = $this->extractHeadings($xpath, 'h3');
        $mainNode = $this->resolveMainNode($xpath, $dom);
        $content = $this->extractReadableText($mainNode);
        $content = trim(preg_replace('/\s+/u', ' ', $content) ?? '');

        if (mb_strlen($content) < 200) {
            Log::warning('HTML extraction returned thin content.', [
                'url' => $url,
                'content_length' => mb_strlen($content),
                'content_preview' => mb_substr($content, 0, 120),
            ]);
        }

        $images = $xpath->query('//img');
        $links = $xpath->query('//a[@href]');

        [$internalLinks, $externalLinks] = $this->countLinks($links, $url);
        $missingAltCount = 0;

        foreach ($images ?: [] as $image) {
            if ($image instanceof DOMElement && trim((string) $image->getAttribute('alt')) === '') {
                $missingAltCount++;
            }
        }

        return [
            'url' => $url,
            'title' => $title,
            'metaDescription' => $metaDescription,
            'canonicalUrl' => $canonicalUrl,
            'headings' => [
                'h1' => $h1,
                'h2' => $h2,
                'h3' => $h3,
            ],
            'metrics' => [
                'wordCount' => str_word_count(strip_tags($content)),
                'imageCount' => $images?->count() ?? 0,
                'missingAltCount' => $missingAltCount,
                'internalLinkCount' => $internalLinks,
                'externalLinkCount' => $externalLinks,
                'hasCanonical' => $canonicalUrl !== '',
                'titleLength' => mb_strlen($title),
                'metaDescriptionLength' => mb_strlen($metaDescription),
                'h1Count' => count($h1),
            ],
            'content' => mb_substr($content, 0, (int) config('services.audit.max_content_chars', 18000)),
            'source' => 'html',
        ];
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

                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), $blockedTags, true)) {
                    $node->removeChild($child);
                    continue;
                }

                $this->removeNoise($child);
            }
        }
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
