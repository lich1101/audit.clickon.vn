<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

class CategoryInputParser
{
    /**
     * @return array<int, array{name:string,url:string}>
     */
    public static function parse(string $input): array
    {
        $categories = collect(preg_split('/\R/', $input) ?: [])
            ->map(fn (string $line): ?array => self::parseLine(trim($line)))
            ->filter()
            ->unique(fn (array $category): string => Str::lower($category['url']))
            ->values()
            ->all();

        if ($categories === []) {
            throw new InvalidArgumentException('Cần ít nhất một danh mục hợp lệ.');
        }

        return $categories;
    }

    public static function formatLine(string $name, string $url): string
    {
        return sprintf('`%s` - `%s`', trim($name), trim($url));
    }

    /**
     * @return array{name:string,url:string}|null
     */
    private static function parseLine(string $line): ?array
    {
        if ($line === '') {
            return null;
        }

        if (preg_match('/^`([^`]+)`\s*-\s*`([^`]+)`\s*$/u', $line, $matches) === 1) {
            return self::validatePair(trim($matches[1]), trim($matches[2]), $line);
        }

        $tabParts = array_values(array_filter(array_map('trim', explode("\t", $line)), fn (string $part): bool => $part !== ''));

        if (count($tabParts) >= 2) {
            $url = $tabParts[count($tabParts) - 1];
            $name = trim(implode(' ', array_slice($tabParts, 0, -1)));

            return self::validatePair($name, $url, $line);
        }

        if (preg_match('/(https?:\/\/\S+)$/iu', $line, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $url = trim($matches[1][0]);
            $name = trim((string) preg_replace('/[\t,\-–—:|]+$/u', '', substr($line, 0, $matches[1][1])));

            return self::validatePair($name, $url, $line);
        }

        throw new InvalidArgumentException("Dòng danh mục không hợp lệ: {$line}. Dùng `Tên danh mục` - `https://url-danh-muc` mỗi dòng.");
    }

    /**
     * @return array{name:string,url:string}|null
     */
    private static function validatePair(string $name, string $url, string $line): ?array
    {
        if ($name === '' || $url === '') {
            throw new InvalidArgumentException("Tên hoặc URL danh mục trống ở dòng: {$line}");
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("URL danh mục không hợp lệ: {$url}");
        }

        return [
            'name' => $name,
            'url' => $url,
        ];
    }
}
