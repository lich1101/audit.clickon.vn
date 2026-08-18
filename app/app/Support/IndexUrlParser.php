<?php

namespace App\Support;

use RuntimeException;

class IndexUrlParser
{
    private const WS_EDGE = "/^[\\s\\x{00a0}]+|[\\s\\x{00a0}]+$/u";

    public static function normalizeUrlInput(mixed $raw): string
    {
        if ($raw === null) {
            throw new RuntimeException('URL is empty');
        }

        $cleaned = preg_replace(self::WS_EDGE, '', (string) $raw) ?? '';

        if ($cleaned === '') {
            throw new RuntimeException('URL is empty after trim');
        }

        return $cleaned;
    }

    public static function urlHash(string $urlExact): string
    {
        return hash('sha256', $urlExact);
    }

    /**
     * @return array{url_exact:string,site_origin:string,site_host:string}
     */
    public static function extractSiteOrigin(string $raw): array
    {
        $exact = self::normalizeUrlInput($raw);
        $parts = parse_url($exact);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException("URL không hợp lệ: {$exact}");
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new RuntimeException("URL thiếu host: {$exact}");
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $origin = strtolower($parts['scheme']).'://'.$host.$port.'/';

        return [
            'url_exact' => $exact,
            'site_origin' => $origin,
            'site_host' => $host,
        ];
    }

    /**
     * @return array{site_origin:string,site_host:string}
     */
    public static function parseSiteField(string $raw): array
    {
        $site = self::normalizeUrlInput($raw);
        if (! preg_match('/^https?:\\/\\//i', $site)) {
            $site = 'https://'.$site;
        }

        return self::extractSiteOrigin($site);
    }

    /**
     * @return list<array{url_exact:string,site_origin:string,site_host:string}>
     */
    public static function parseBulkLinks(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $found = [];
        if (preg_match_all('/https?:\\/\\/[^\\s,;\'"]+/i', $text, $matches)) {
            $found = $matches[0];
        }

        if ($found === []) {
            $parts = preg_split("/[\\s,\\'\"\\x{201c}\\x{201d}\\x{2018}\\x{2019}]+/u", $text) ?: [];
            foreach ($parts as $part) {
                $part = trim($part);
                if (preg_match('/^https?:\\/\\//i', $part)) {
                    $found[] = $part;
                }
            }
        }

        $seen = [];
        $out = [];

        foreach ($found as $raw) {
            try {
                $raw = self::normalizeUrlInput($raw);
                $raw = preg_replace('/[).;\\]}]+$/', '', $raw) ?? $raw;
                $link = self::extractSiteOrigin($raw);
                if (isset($seen[$link['url_exact']])) {
                    continue;
                }
                $seen[$link['url_exact']] = true;
                $out[] = $link;
            } catch (RuntimeException) {
                continue;
            }
        }

        return $out;
    }

    public static function codeFromHost(string $host): string
    {
        $code = strtolower(preg_replace('/[^a-z0-9]+/', '-', $host) ?? 'site');
        $code = trim($code, '-');

        return $code === '' ? 'site' : substr($code, 0, 64);
    }
}
