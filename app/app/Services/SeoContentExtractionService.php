<?php

namespace App\Services;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SeoContentExtractionService
{
    /**
     * @return array<string, mixed>
     */
    public function extract(string $url): array
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
        $queries = [
            '//article',
            '//main',
            '//*[@role="main"]',
            '//*[contains(@class, "entry-content")]',
            '//*[contains(@class, "post-content")]',
            '//*[contains(@class, "article-content")]',
            '//*[contains(@class, "content")]',
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if ($nodes && $nodes->count() > 0) {
                return $nodes->item(0);
            }
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
