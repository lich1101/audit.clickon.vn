<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiSeoAuditService
{
    private const DEFAULT_CHECKLIST = <<<'TEXT'
Kiểm tra Onpage SEO theo các nhóm:
- Title tag: rõ chủ đề, chứa từ khóa chính, không quá ngắn hoặc quá dài.
- Meta description: có mô tả, có ý định nhấp, liên quan nội dung.
- H1/H2/H3: cấu trúc heading logic, không thiếu H1.
- Tính liên quan nội dung với từ khóa chính và search intent.
- Canonical, internal links, hình ảnh và alt text.
- Độ đầy đủ và chiều sâu nội dung.
- Định hướng nâng cấp nội dung để tăng topical relevance.
TEXT;

    /**
     * @param  array<string, mixed>  $page
     * @param  array<int, array{name:string,url:string}>  $categories
     * @return array<string, mixed>
     */
    public function analyze(array $page, array $categories, ?string $checklistText = null): array
    {
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $payload = [
            'model' => config('services.openai.model', 'gpt-5.5'),
            'reasoning' => [
                'effort' => config('services.openai.reasoning_effort', 'medium'),
            ],
            'text' => [
                'format' => [
                    'type' => 'json_object',
                ],
            ],
            'input' => [
                [
                    'role' => 'developer',
                    'content' => implode("\n", [
                        'Bạn là chuyên gia SEO Google có 20 năm kinh nghiệm.',
                        'Nhiệm vụ: phân tích đúng 1 URL và trả về đúng 1 JSON object.',
                        'Chỉ chọn 1 từ khóa SEO chính duy nhất.',
                        'Nếu có danh mục, chỉ gán đúng 1 danh mục phù hợp tuyệt đối nhất; nếu không có danh mục phù hợp thì để null.',
                        'Trả về JSON với các khóa:',
                        'primaryKeyword, categoryName, categoryUrl, auditScore, auditFindings, auditRecommendations, contentRevisionDirection',
                        'auditFindings là mảng string 3-8 ý ngắn.',
                        'auditRecommendations là mảng string 3-8 ý hành động cụ thể.',
                        'contentRevisionDirection là string dài 2-4 câu.',
                        'auditScore là số nguyên từ 0 đến 100.',
                        'JSON only. No markdown.',
                    ]),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'task' => 'Phân tích URL SEO và audit onpage.',
                        'url' => $page['url'],
                        'page' => [
                            'title' => $page['title'],
                            'metaDescription' => $page['metaDescription'],
                            'canonicalUrl' => $page['canonicalUrl'],
                            'headings' => $page['headings'],
                            'metrics' => $page['metrics'],
                            'contentExcerpt' => $page['content'],
                        ],
                        'categoryCandidates' => $categories,
                        'auditChecklist' => trim($checklistText ?: self::DEFAULT_CHECKLIST),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('services.openai.timeout_seconds', 180))
            ->post('https://api.openai.com/v1/responses', $payload);

        $response->throw();

        $decoded = $response->json();
        $text = $this->extractTextFromResponse($decoded);
        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new RuntimeException('OpenAI response did not contain valid JSON.');
        }

        return [
            'primaryKeyword' => $this->stringOrNull($data['primaryKeyword'] ?? null),
            'categoryName' => $this->stringOrNull($data['categoryName'] ?? null),
            'categoryUrl' => $this->stringOrNull($data['categoryUrl'] ?? null),
            'auditScore' => max(0, min(100, (int) ($data['auditScore'] ?? 0))),
            'auditFindings' => $this->normalizeStringList($data['auditFindings'] ?? []),
            'auditRecommendations' => $this->normalizeStringList($data['auditRecommendations'] ?? []),
            'contentRevisionDirection' => $this->stringOrNull($data['contentRevisionDirection'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractTextFromResponse(array $response): string
    {
        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                $text = Arr::get($content, 'text');

                if (is_string($text) && trim($text) !== '') {
                    return $text;
                }
            }
        }

        throw new RuntimeException('Unable to extract text from OpenAI response.');
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value
        )));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
