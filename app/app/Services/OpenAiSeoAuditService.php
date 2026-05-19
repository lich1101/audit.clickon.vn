<?php

namespace App\Services;

class OpenAiSeoAuditService
{
    public function __construct(
        private readonly SeoAiAuditService $seoAiAuditService,
    ) {
    }

    /**
     * Backward-compatible adapter for older code paths. New audit runs use
     * SeoAiAuditService directly so provider/model/category context can be stored per run.
     *
     * @param  array<string, mixed>  $page
     * @param  array<int, array{name:string,url:string}>  $categories
     * @return array<string, mixed>
     */
    public function analyze(array $page, array $categories, ?string $checklistText = null): array
    {
        $categoryContexts = array_map(
            fn (array $category): array => [
                'name' => $category['name'] ?? null,
                'url' => $category['url'] ?? null,
                'title' => null,
                'metaDescription' => null,
                'headings' => [],
                'contentExcerpt' => null,
                'source' => 'legacy',
                'error' => null,
            ],
            $categories
        );

        return $this->seoAiAuditService->analyze(
            page: $page,
            categoryContexts: $categoryContexts,
            checklistText: $checklistText,
            provider: 'openai',
            model: null,
        );
    }
}
