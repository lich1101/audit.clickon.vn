<?php

namespace App\Services;

use App\Models\AuditPromptTemplate;
use App\Models\AuditRun;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SeoAiAuditService
{
    private static function defaultChecklist(): string
    {
        $path = resource_path('audit/seo-checklist.txt');

        if (is_readable($path)) {
            return trim((string) file_get_contents($path));
        }

        return <<<'TEXT'
CHECKLIST AUDIT SEO — CLICKON (fallback)
Chấm theo 25 tiêu chí: Kỹ thuật SEO tối đa 24đ, Nội dung & chuyên môn tối đa 6đ, tổng 30đ.
auditScore = làm tròn((điểm_kỹ_thuật + điểm_nội_dung) / 30 × 100).
Hướng xử lý: Viết lại | Audit Content | Giữ nguyên | Redirect theo ma trận trong checklist.
TEXT;
    }

    public function __construct(
        private readonly AuditPromptTemplateService $promptTemplateService,
        private readonly AuditAiStepResponseStorageService $aiStepResponseStorage,
    ) {
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<int, array<string, mixed>>  $categoryContexts
     * @return array<string, mixed>
     */
    public function analyze(
        array $page,
        array $categoryContexts,
        ?string $checklistText = null,
        string $provider = 'openai',
        ?string $model = null,
        ?int $auditRunId = null,
    ): array {
        $resolvedModel = $model ?: $this->defaultModelForProvider($provider);
        $keywordAndCategory = $this->analyzeKeywordAndCategory($provider, $resolvedModel, $page, $categoryContexts, $auditRunId);
        $audit = $this->analyzeOnpage($provider, $resolvedModel, $page, $categoryContexts, $checklistText, $keywordAndCategory, $auditRunId);

        return [
            'primaryKeyword' => $keywordAndCategory['primaryKeyword'],
            'categoryName' => $keywordAndCategory['categoryName'],
            'categoryUrl' => $keywordAndCategory['categoryUrl'],
            'categoryMatchReason' => $keywordAndCategory['categoryMatchReason'],
            'auditScore' => $audit['auditScore'],
            'auditFindings' => $audit['auditFindings'],
            'auditRecommendations' => $audit['auditRecommendations'],
            'contentRevisionDirection' => $audit['contentRevisionDirection'],
            'promptSnapshots' => [
                'keywordCategory' => $keywordAndCategory['promptSnapshot'],
                'onpageAudit' => $audit['promptSnapshot'],
            ],
            'usageEvents' => [
                $keywordAndCategory['usage'],
                $audit['usage'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $targetUrls
     * @param  array<int, array<string, mixed>>  $categories
     * @return array{items: array<int, array<string, mixed>>, promptSnapshots: array<string, mixed>, usageEvents: array<int, array<string, mixed>>}
     */
    public function analyzeBatchUrlOnly(
        array $targetUrls,
        array $categories,
        ?string $checklistText = null,
        string $provider = 'openai',
        ?string $model = null,
        ?int $auditRunId = null,
    ): array {
        $resolvedModel = $model ?: $this->defaultModelForProvider($provider);
        $keywordAndCategory = $this->analyzeBatchKeywordAndCategory($provider, $resolvedModel, $targetUrls, $categories, $auditRunId);

        $this->assertAuditRunActive($auditRunId);

        $audit = $this->analyzeBatchOnpage($provider, $resolvedModel, $targetUrls, $categories, $checklistText, $keywordAndCategory['items'], $auditRunId);

        $itemsByUrl = collect($keywordAndCategory['items'])
            ->keyBy(fn (array $item): string => (string) ($item['targetUrl'] ?? ''));

        $items = array_map(function (array $auditItem) use ($itemsByUrl): array {
            $targetUrl = (string) ($auditItem['targetUrl'] ?? '');
            $keywordItem = $itemsByUrl->get($targetUrl, []);

            return [
                'targetUrl' => $targetUrl,
                'primaryKeyword' => $this->stringOrNull($auditItem['primaryKeyword'] ?? $keywordItem['primaryKeyword'] ?? null),
                'categoryName' => $this->stringOrNull($auditItem['categoryName'] ?? $keywordItem['categoryName'] ?? null),
                'categoryUrl' => $this->stringOrNull($auditItem['categoryUrl'] ?? $keywordItem['categoryUrl'] ?? null),
                'categoryMatchReason' => $this->stringOrNull($auditItem['categoryMatchReason'] ?? $keywordItem['categoryMatchReason'] ?? null),
                'auditScore' => max(0, min(100, (int) ($auditItem['auditScore'] ?? 0))),
                'auditFindings' => $this->normalizeStringList($auditItem['auditFindings'] ?? []),
                'auditRecommendations' => $this->normalizeStringList($auditItem['auditRecommendations'] ?? []),
                'contentRevisionDirection' => $this->stringOrNull($auditItem['contentRevisionDirection'] ?? null),
            ];
        }, $audit['items']);

        return [
            'items' => $items,
            'promptSnapshots' => [
                'keywordCategory' => $keywordAndCategory['promptSnapshot'],
                'onpageAudit' => $audit['promptSnapshot'],
            ],
            'usageEvents' => [
                $keywordAndCategory['usage'],
                $audit['usage'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $targetUrls
     * @param  array<int, array<string, mixed>>  $categories
     * @return array{items: array<int, array<string, mixed>>, promptSnapshot: array<string, mixed>, usageEvents: array<int, array<string, mixed>>}
     */
    public function analyzeBatchKeywordCategoryUrlOnly(
        array $targetUrls,
        array $categories,
        string $provider = 'openai',
        ?string $model = null,
        ?int $auditRunId = null,
        ?string $persistStep = null,
    ): array {
        $resolvedModel = $model ?: $this->defaultModelForProvider($provider);
        $result = $this->analyzeBatchKeywordAndCategory($provider, $resolvedModel, $targetUrls, $categories, $auditRunId, $persistStep);

        return [
            'items' => $result['items'],
            'promptSnapshot' => $result['promptSnapshot'],
            'usageEvents' => [
                $result['usage'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $targetUrls
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<int, array<string, mixed>>  $keywordCategoryItems
     * @return array{items: array<int, array<string, mixed>>, promptSnapshot: array<string, mixed>, usageEvents: array<int, array<string, mixed>>}
     */
    public function analyzeBatchOnpageUrlOnly(
        array $targetUrls,
        array $categories,
        ?string $checklistText,
        array $keywordCategoryItems,
        string $provider = 'openai',
        ?string $model = null,
        ?int $auditRunId = null,
        ?string $persistStep = null,
    ): array {
        $resolvedModel = $model ?: $this->defaultModelForProvider($provider);
        $result = $this->analyzeBatchOnpage($provider, $resolvedModel, $targetUrls, $categories, $checklistText, $keywordCategoryItems, $auditRunId, $persistStep);

        return [
            'items' => $result['items'],
            'promptSnapshot' => $result['promptSnapshot'],
            'usageEvents' => [
                $result['usage'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<int, array<string, mixed>>  $categoryContexts
     * @return array<string, mixed>
     */
    private function analyzeKeywordAndCategory(string $provider, ?string $model, array $page, array $categoryContexts, ?int $auditRunId = null): array
    {
        $prompts = $this->promptTemplateService->render(AuditPromptTemplate::STEP_KEYWORD_CATEGORY_MAPPING, [
            'url' => $page['url'],
            'page_json' => $this->pagePayload($page),
            'article_content' => $page['content'] ?? '',
            'category_contexts_json' => $this->categoryPayload($categoryContexts),
            'categories_json' => array_map(
                fn (array $category): array => [
                    'name' => $category['name'] ?? null,
                    'url' => $category['url'] ?? null,
                ],
                $categoryContexts
            ),
        ]);

        $response = $this->requestJson(
            provider: $provider,
            model: $model,
            systemPrompt: $prompts['system'],
            userPrompt: $prompts['user'],
            schema: $this->keywordCategorySchema(),
            auditRunId: $auditRunId,
        );

        $data = $response['data'];

        return [
            'primaryKeyword' => $this->stringOrNull($data['primaryKeyword'] ?? null),
            'categoryName' => $this->stringOrNull($data['categoryName'] ?? null),
            'categoryUrl' => $this->stringOrNull($data['categoryUrl'] ?? null),
            'categoryMatchReason' => $this->stringOrNull($data['categoryMatchReason'] ?? null),
            'promptSnapshot' => $this->promptSnapshot('keyword_category_mapping', $provider, $model, $prompts),
            'usage' => array_merge($response['usage'], ['step' => 'keyword_category_mapping']),
        ];
    }

    /**
     * @param  array<int, string>  $targetUrls
     * @param  array<int, array<string, mixed>>  $categories
     * @return array{items: array<int, array<string, mixed>>, promptSnapshot: array<string, mixed>, usage: array<string, mixed>}
     */
    private function analyzeBatchKeywordAndCategory(
        string $provider,
        ?string $model,
        array $targetUrls,
        array $categories,
        ?int $auditRunId = null,
        ?string $persistStep = null,
    ): array
    {
        $categoryPayload = array_map(
            fn (array $category): array => [
                'name' => $category['name'] ?? null,
                'url' => $category['url'] ?? null,
            ],
            $categories
        );

        $prompts = $this->promptTemplateService->render(AuditPromptTemplate::STEP_KEYWORD_CATEGORY_MAPPING, [
            'url' => '',
            'target_urls_json' => $targetUrls,
            'target_urls_text' => implode("\n", $targetUrls),
            'categories_json' => $categoryPayload,
            'category_contexts_json' => $categoryPayload,
            'page_json' => ['mode' => 'url_only_batch', 'targetUrls' => $targetUrls],
            'article_content' => '',
        ]);

        $batchContract = implode("\n", [
            '=== RUNTIME BATCH CONTRACT — AUTHORITATIVE ===',
            'Mode: URL-only chunk batch. Process all URLs provided in this chunk in one response.',
            'Do not claim that page HTML/content/title/meta/headings were crawled.',
            'Return exactly this JSON shape and include every target URL once:',
            '{"items":[{"targetUrl":"string","primaryKeyword":"string","categoryName":"string","categoryUrl":"string","categoryMatchReason":"string"}]}',
            'OUTPUT: single JSON object only. First char {, last char }. No markdown/report prose.',
        ]);
        $prompts['system'] .= "\n\n".$batchContract;
        $prompts['developer'] = $prompts['system'];
        $prompts['user'] .= "\n\n".implode("\n", [
            $batchContract,
            'Target URLs JSON:',
            json_encode($targetUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Allowed categories JSON:',
            json_encode($categoryPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);

        $response = $this->requestJson(
            provider: $provider,
            model: $model,
            systemPrompt: $prompts['system'],
            userPrompt: $prompts['user'],
            schema: $this->batchKeywordCategorySchema(),
            auditRunId: $auditRunId,
            persistStep: $persistStep ?? 'batch_keyword_category_mapping',
        );

        $data = $response['data'];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        return [
            'items' => array_values(array_filter(array_map(
                fn (mixed $item): ?array => is_array($item) ? [
                    'targetUrl' => $this->stringOrNull($item['targetUrl'] ?? null),
                    'primaryKeyword' => $this->stringOrNull($item['primaryKeyword'] ?? null),
                    'categoryName' => $this->stringOrNull($item['categoryName'] ?? null),
                    'categoryUrl' => $this->stringOrNull($item['categoryUrl'] ?? null),
                    'categoryMatchReason' => $this->stringOrNull($item['categoryMatchReason'] ?? null),
                ] : null,
                $items
            ))),
            'promptSnapshot' => $this->promptSnapshot('keyword_category_mapping', $provider, $model, $prompts),
            'usage' => array_merge($response['usage'], ['step' => 'batch_keyword_category_mapping']),
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<int, array<string, mixed>>  $categoryContexts
     * @param  array<string, mixed>  $keywordAndCategory
     * @return array<string, mixed>
     */
    private function analyzeOnpage(
        string $provider,
        ?string $model,
        array $page,
        array $categoryContexts,
        ?string $checklistText,
        array $keywordAndCategory,
        ?int $auditRunId = null,
    ): array {
        $prompts = $this->promptTemplateService->render(AuditPromptTemplate::STEP_ONPAGE_AUDIT, [
            'url' => $page['url'],
            'primary_keyword' => $keywordAndCategory['primaryKeyword'],
            'category_json' => [
                'categoryName' => $keywordAndCategory['categoryName'],
                'categoryUrl' => $keywordAndCategory['categoryUrl'],
                'categoryMatchReason' => $keywordAndCategory['categoryMatchReason'],
            ],
            'categories_json' => $this->categoryPayload($categoryContexts),
            'page_json' => $this->pagePayload($page),
            'article_content' => $page['content'] ?? '',
            'checklist' => trim($checklistText ?: self::defaultChecklist()),
        ]);

        $response = $this->requestJson(
            provider: $provider,
            model: $model,
            systemPrompt: $prompts['system'],
            userPrompt: $prompts['user'],
            schema: $this->onpageSchema(),
            auditRunId: $auditRunId,
        );

        $data = $response['data'];

        return [
            'auditScore' => max(0, min(100, (int) ($data['auditScore'] ?? 0))),
            'auditFindings' => $this->normalizeStringList($data['auditFindings'] ?? []),
            'auditRecommendations' => $this->normalizeStringList($data['auditRecommendations'] ?? []),
            'contentRevisionDirection' => $this->stringOrNull($data['contentRevisionDirection'] ?? null),
            'promptSnapshot' => $this->promptSnapshot('onpage_audit', $provider, $model, $prompts),
            'usage' => array_merge($response['usage'], ['step' => 'onpage_audit']),
        ];
    }

    /**
     * @param  array<int, string>  $targetUrls
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<int, array<string, mixed>>  $keywordCategoryItems
     * @return array{items: array<int, array<string, mixed>>, promptSnapshot: array<string, mixed>, usage: array<string, mixed>}
     */
    private function analyzeBatchOnpage(
        string $provider,
        ?string $model,
        array $targetUrls,
        array $categories,
        ?string $checklistText,
        array $keywordCategoryItems,
        ?int $auditRunId = null,
        ?string $persistStep = null,
    ): array {
        $categoryPayload = array_map(
            fn (array $category): array => [
                'name' => $category['name'] ?? null,
                'url' => $category['url'] ?? null,
            ],
            $categories
        );

        $prompts = $this->promptTemplateService->render(AuditPromptTemplate::STEP_ONPAGE_AUDIT, [
            'url' => '',
            'target_urls_json' => $targetUrls,
            'target_urls_text' => implode("\n", $targetUrls),
            'categories_json' => $categoryPayload,
            'keyword_category_results_json' => $keywordCategoryItems,
            'primary_keyword' => '',
            'category_json' => [],
            'category_contexts_json' => [],
            'page_json' => ['mode' => 'url_only_batch', 'targetUrls' => $targetUrls],
            'article_content' => '',
            'checklist' => trim($checklistText ?: self::defaultChecklist()),
        ]);

        $batchContract = implode("\n", [
            '=== RUNTIME BATCH CONTRACT — AUTHORITATIVE ===',
            'Mode: URL-only chunk batch. Process all URLs provided in this chunk in one response.',
            'Backend did not crawl content, title, meta, headings, images or internal links.',
            'For unverified checklist criteria, state "không kiểm chứng được" instead of inventing evidence.',
            'Return exactly this JSON shape and include every target URL once:',
            '{"items":[{"targetUrl":"string","primaryKeyword":"string","categoryName":"string","categoryUrl":"string","categoryMatchReason":"string","auditScore":number,"auditFindings":["string"],"auditRecommendations":["string"],"contentRevisionDirection":"string"}]}',
            'OUTPUT: single JSON object only. First char {, last char }. No markdown/report prose.',
        ]);
        $prompts['system'] .= "\n\n".$batchContract;
        $prompts['developer'] = $prompts['system'];
        $prompts['user'] .= "\n\n".implode("\n", [
            $batchContract,
            'Target URLs JSON:',
            json_encode($targetUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Allowed categories JSON:',
            json_encode($categoryPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Keyword/category results JSON:',
            json_encode($keywordCategoryItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);

        $response = $this->requestJson(
            provider: $provider,
            model: $model,
            systemPrompt: $prompts['system'],
            userPrompt: $prompts['user'],
            schema: $this->batchOnpageSchema(),
            auditRunId: $auditRunId,
            persistStep: $persistStep ?? 'batch_onpage_audit',
        );

        $data = $response['data'];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        return [
            'items' => array_values(array_filter(array_map(
                fn (mixed $item): ?array => is_array($item) ? [
                    'targetUrl' => $this->stringOrNull($item['targetUrl'] ?? null),
                    'primaryKeyword' => $this->stringOrNull($item['primaryKeyword'] ?? null),
                    'categoryName' => $this->stringOrNull($item['categoryName'] ?? null),
                    'categoryUrl' => $this->stringOrNull($item['categoryUrl'] ?? null),
                    'categoryMatchReason' => $this->stringOrNull($item['categoryMatchReason'] ?? null),
                    'auditScore' => max(0, min(100, (int) ($item['auditScore'] ?? 0))),
                    'auditFindings' => $this->normalizeStringList($item['auditFindings'] ?? []),
                    'auditRecommendations' => $this->normalizeStringList($item['auditRecommendations'] ?? []),
                    'contentRevisionDirection' => $this->stringOrNull($item['contentRevisionDirection'] ?? null),
                ] : null,
                $items
            ))),
            'promptSnapshot' => $this->promptSnapshot('onpage_audit', $provider, $model, $prompts),
            'usage' => array_merge($response['usage'], ['step' => 'batch_onpage_audit']),
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function pagePayload(array $page): array
    {
        return [
            'url' => $page['url'],
            'title' => $page['title'],
            'metaDescription' => $page['metaDescription'],
            'canonicalUrl' => $page['canonicalUrl'],
            'headings' => $page['headings'],
            'metrics' => $page['metrics'],
            'contentExcerpt' => $page['content'],
            'source' => $page['source'] ?? null,
            'extractionError' => $page['extractionError'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $categoryContexts
     * @return array<int, array<string, mixed>>
     */
    private function categoryPayload(array $categoryContexts): array
    {
        return array_values(array_map(
            fn (array $category): array => [
                'name' => $category['name'] ?? null,
                'url' => $category['url'] ?? null,
                'title' => $category['title'] ?? null,
                'metaDescription' => $category['metaDescription'] ?? null,
                'headings' => $category['headings'] ?? [],
                'contentExcerpt' => $category['contentExcerpt'] ?? null,
                'source' => $category['source'] ?? null,
                'error' => $category['error'] ?? null,
            ],
            $categoryContexts
        ));
    }

    /**
     * @param  array{system:string,developer:string,user:string}  $prompts
     * @return array<string, mixed>
     */
    private function promptSnapshot(string $step, string $provider, ?string $model, array $prompts): array
    {
        return [
            'step' => $step,
            'provider' => $provider,
            'model' => $model ?: $this->defaultModelForProvider($provider),
            'systemPrompt' => $prompts['system'],
            'userPrompt' => $prompts['user'],
            'createdAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{data: array<string, mixed>, usage: array{provider: string, model: string, input_tokens: int, output_tokens: int, total_tokens: int}}
     */
    private function requestJson(
        string $provider,
        ?string $model,
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        ?int $auditRunId = null,
        ?string $persistStep = null,
    ): array {
        $resolvedModel = $model ?: $this->defaultModelForProvider($provider);
        $providerLabel = match ($provider) {
            'openai' => 'OpenAI',
            'gemini' => 'Gemini',
            'gemini_deep_research' => 'Gemini Deep Research',
            default => $provider,
        };

        $raw = match ($provider) {
            'openai' => $this->requestOpenAiRaw($resolvedModel, $systemPrompt, $userPrompt, $provider),
            'gemini' => $this->requestGeminiRaw($resolvedModel, $systemPrompt, $userPrompt, $schema, $provider),
            'gemini_deep_research' => $this->requestGeminiDeepResearchRaw($resolvedModel, $systemPrompt, $userPrompt, $auditRunId, $provider),
            default => throw new RuntimeException("Unsupported AI provider [{$provider}]."),
        };

        try {
            $data = $this->decodeJsonText($raw['rawText'], $providerLabel);
        } catch (RuntimeException $exception) {
            if ($auditRunId && $persistStep) {
                $this->persistAiStepResponse($auditRunId, $persistStep, [
                    'step' => $persistStep,
                    'stepLabel' => $this->stepLabel($persistStep),
                    'status' => 'parse_failed',
                    'provider' => $provider,
                    'model' => $resolvedModel,
                    'rawText' => $raw['rawText'],
                    'interactionId' => $raw['interactionId'] ?? null,
                    'parseError' => $exception->getMessage(),
                    'usage' => $raw['usage'],
                    'createdAt' => now()->toIso8601String(),
                ]);
            }

            throw $exception;
        }

        if ($auditRunId && $persistStep) {
            $this->persistAiStepResponse($auditRunId, $persistStep, [
                'step' => $persistStep,
                'stepLabel' => $this->stepLabel($persistStep),
                'status' => 'parsed',
                'provider' => $provider,
                'model' => $resolvedModel,
                'rawText' => $raw['rawText'],
                'interactionId' => $raw['interactionId'] ?? null,
                'parsed' => $data,
                'usage' => $raw['usage'],
                'createdAt' => now()->toIso8601String(),
            ]);
        }

        return [
            'data' => $data,
            'usage' => $raw['usage'],
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function persistAiStepResponse(int $auditRunId, string $step, array $record): void
    {
        $run = AuditRun::query()->find($auditRunId);

        if (! $run) {
            return;
        }

        $responses = is_array($run->ai_step_responses) ? $run->ai_step_responses : [];
        $rawText = (string) ($record['rawText'] ?? '');

        if ($rawText !== '') {
            try {
                $stored = $this->aiStepResponseStorage->store($run->public_id, $step, $rawText);
                unset($record['rawText']);
                $record = array_merge($record, $stored);
            } catch (RuntimeException $storageException) {
                unset($record['rawText']);
                $record['rawTextStorageError'] = $storageException->getMessage();
                $record['rawTextPreview'] = mb_substr($rawText, 0, 4000);
                $record['rawTextBytes'] = strlen($rawText);

                if (($record['status'] ?? '') !== 'parse_failed') {
                    report($storageException);
                }
            }
        }

        $responses[$step] = $record;
        $run->forceFill(['ai_step_responses' => $responses])->save();
    }

    private function stepLabel(string $step): string
    {
        if (str_starts_with($step, 'batch_keyword_category_mapping_')) {
            return 'Bước 2: Từ khóa + danh mục (chunk)';
        }

        if (str_starts_with($step, 'batch_onpage_audit_')) {
            return 'Bước 3: Audit onpage (chunk)';
        }

        return match ($step) {
            'batch_keyword_category_mapping' => 'Bước 2: Từ khóa + danh mục (batch)',
            'batch_onpage_audit' => 'Bước 3: Audit onpage (batch)',
            'keyword_category_mapping' => 'Từ khóa + danh mục',
            'onpage_audit' => 'Audit onpage',
            default => $step,
        };
    }

    /**
     * @return array{rawText: string, usage: array{provider: string, model: string, input_tokens: int, output_tokens: int, total_tokens: int}}
     */
    private function requestOpenAiRaw(string $model, string $systemPrompt, string $userPrompt, string $provider): array
    {
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $payload = [
            'model' => $model,
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
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout($this->aiHttpTimeoutSeconds())
            ->post('https://api.openai.com/v1/responses', $payload);

        $this->throwIfAiRequestFailed($response, 'OpenAI');

        $body = $response->json();
        $text = $this->extractTextFromOpenAiResponse($body);
        $usageMeta = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $inputTokens = (int) ($usageMeta['input_tokens'] ?? $usageMeta['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usageMeta['output_tokens'] ?? $usageMeta['completion_tokens'] ?? 0);

        return [
            'rawText' => $text,
            'usage' => [
                'provider' => $provider,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{rawText: string, usage: array{provider: string, model: string, input_tokens: int, output_tokens: int, total_tokens: int}}
     */
    private function requestGeminiRaw(string $model, string $systemPrompt, string $userPrompt, array $schema, string $provider): array
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $modelName = $model;
        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        $response = Http::withHeaders([
            'x-goog-api-key' => $apiKey,
            'Content-Type' => 'application/json',
        ])
            ->acceptJson()
            ->timeout($this->aiHttpTimeoutSeconds())
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent", $payload);

        $this->throwIfAiRequestFailed($response, 'Gemini');

        $body = $response->json();
        $meta = is_array($body['usageMetadata'] ?? null) ? $body['usageMetadata'] : [];
        $inputTokens = (int) ($meta['promptTokenCount'] ?? 0);
        $outputTokens = (int) ($meta['candidatesTokenCount'] ?? 0);
        $totalTokens = (int) ($meta['totalTokenCount'] ?? ($inputTokens + $outputTokens));

        return [
            'rawText' => $this->extractTextFromGeminiResponse($body),
            'usage' => [
                'provider' => $provider,
                'model' => $modelName,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
            ],
        ];
    }

    /**
     * @return array{rawText: string, usage: array{provider: string, model: string, input_tokens: int, output_tokens: int, total_tokens: int}, interactionId: string}
     */
    private function requestGeminiDeepResearchRaw(string $model, string $systemPrompt, string $userPrompt, ?int $auditRunId, string $provider): array
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $this->assertAuditRunActive($auditRunId);

        $agent = $model;
        $input = implode("\n\n", [
            $systemPrompt,
            $userPrompt,
            'Bắt buộc trả về một JSON object duy nhất, không markdown, không giải thích ngoài JSON.',
        ]);

        $start = Http::withHeaders([
            'x-goog-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'Api-Revision' => '2026-05-20',
        ])
            ->acceptJson()
            ->timeout($this->aiHttpTimeoutSeconds())
            ->post('https://generativelanguage.googleapis.com/v1beta/interactions', [
                'input' => $input,
                'agent' => $agent,
                'agent_config' => [
                    'type' => 'deep-research',
                    'collaborative_planning' => false,
                ],
                'background' => true,
                'store' => true,
            ]);

        $this->ensureGeminiSuccess($start, 'Gemini Deep Research start failed');

        $interactionId = $start->json('id');

        if (! is_string($interactionId) || $interactionId === '') {
            throw new RuntimeException('Gemini Deep Research did not return an interaction id.');
        }

        $deadline = $this->deepResearchDeadlineUnix();

        do {
            $this->assertAuditRunActive($auditRunId);
            sleep(10);

            $poll = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
                'Api-Revision' => '2026-05-20',
            ])
                ->acceptJson()
                ->timeout($this->aiHttpTimeoutSeconds())
                ->get("https://generativelanguage.googleapis.com/v1beta/interactions/{$interactionId}");

            $this->ensureGeminiSuccess($poll, 'Gemini Deep Research poll failed');
            $payload = $poll->json();
            $status = $payload['status'] ?? null;

            if ($status === 'completed') {
                return [
                    'rawText' => $this->extractTextFromInteraction($payload),
                    'interactionId' => $interactionId,
                    'usage' => [
                        'provider' => $provider,
                        'model' => $agent,
                        'input_tokens' => 0,
                        'output_tokens' => 0,
                        'total_tokens' => 0,
                    ],
                ];
            }

            if (in_array($status, ['failed', 'cancelled'], true)) {
                if ($auditRunId) {
                    $this->persistAiStepResponse($auditRunId, 'gemini_deep_research_interaction', [
                        'step' => 'gemini_deep_research_interaction',
                        'stepLabel' => 'Gemini Deep Research (interaction failed)',
                        'status' => (string) $status,
                        'provider' => $provider,
                        'model' => $agent,
                        'interactionId' => $interactionId,
                        'rawText' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'parseError' => json_encode($payload['error'] ?? [], JSON_UNESCAPED_UNICODE),
                        'createdAt' => now()->toIso8601String(),
                    ]);
                }

                throw new RuntimeException('Gemini Deep Research failed: '.json_encode($payload['error'] ?? [], JSON_UNESCAPED_UNICODE));
            }

            if (isset($payload['error']['message'])) {
                throw new RuntimeException('Gemini Deep Research error: '.$payload['error']['message']);
            }
        } while ($deadline === null || time() < $deadline);

        throw new RuntimeException("Gemini Deep Research timed out for interaction [{$interactionId}].");
    }

    private function aiHttpTimeoutSeconds(): int
    {
        $auditTimeout = (int) config('services.audit.ai_http_timeout_seconds', 0);

        if ($auditTimeout > 0) {
            return $auditTimeout;
        }

        return 0;
    }

    /**
     * null = poll không giới hạn (chỉ dừng khi completed/failed hoặc user cancel run).
     */
    private function deepResearchDeadlineUnix(): ?int
    {
        $seconds = (int) config('services.gemini.deep_research_timeout_seconds', 0);

        if ($seconds <= 0) {
            return null;
        }

        return time() + $seconds;
    }

    private function assertAuditRunActive(?int $auditRunId): void
    {
        if (! $auditRunId) {
            return;
        }

        $run = AuditRun::query()->find($auditRunId);

        if (! $run || $run->cancelled_at !== null || $run->status === 'failed') {
            throw new RuntimeException('Audit run stopped.');
        }
    }

    private function ensureGeminiSuccess(\Illuminate\Http\Client\Response $response, string $fallback): void
    {
        $payload = $response->json();

        if (is_array($payload) && isset($payload['error']['message'])) {
            $code = $payload['error']['code'] ?? null;

            if ($code === 'permission_denied') {
                throw new RuntimeException('Gemini Deep Research access denied: '.$payload['error']['message'].' Please contact Google support or enable access for deep-research-preview-04-2026.');
            }

            throw new RuntimeException($fallback.': '.$payload['error']['message']);
        }

        $this->throwIfAiRequestFailed($response, 'Gemini Deep Research');
    }

    private function throwIfAiRequestFailed(\Illuminate\Http\Client\Response $response, string $provider): void
    {
        if ($response->successful()) {
            return;
        }

        try {
            $response->throw();
        } catch (RequestException $exception) {
            $payload = $exception->response->json();
            $message = null;

            if (is_array($payload)) {
                $message = Arr::get($payload, 'error.message')
                    ?? Arr::get($payload, 'message')
                    ?? Arr::get($payload, 'error');
            }

            if (! is_string($message) || trim($message) === '') {
                $message = mb_substr($exception->response->body(), 0, 500);
            }

            $message = trim(preg_replace('/\s+/', ' ', (string) $message) ?? (string) $message);

            if ($exception->response->status() === 429) {
                throw new RuntimeException("{$provider} rate limit (429): {$message}. Hãy giảm số URL trong một batch, đổi model nhẹ hơn hoặc chờ quota reset.");
            }

            throw new RuntimeException("{$provider} API lỗi HTTP {$exception->response->status()}: {$message}");
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractTextFromOpenAiResponse(array $response): string
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
     * @param  array<string, mixed>  $response
     */
    private function extractTextFromGeminiResponse(array $response): string
    {
        $parts = Arr::get($response, 'candidates.0.content.parts', []);

        foreach ($parts as $part) {
            $text = $part['text'] ?? null;

            if (is_string($text) && trim($text) !== '') {
                return $text;
            }
        }

        throw new RuntimeException('Unable to extract text from Gemini response.');
    }

    /**
     * @param  array<string, mixed>  $interaction
     */
    private function extractTextFromInteraction(array $interaction): string
    {
        $outputs = $interaction['outputs'] ?? [];

        for ($index = count($outputs) - 1; $index >= 0; $index--) {
            $text = $outputs[$index]['text'] ?? null;

            if (is_string($text) && trim($text) !== '') {
                return $text;
            }
        }

        $steps = $interaction['steps'] ?? [];

        for ($index = count($steps) - 1; $index >= 0; $index--) {
            foreach ($steps[$index]['content'] ?? [] as $content) {
                $text = $content['text'] ?? null;

                if (is_string($text) && trim($text) !== '') {
                    return $text;
                }
            }
        }

        throw new RuntimeException('Unable to extract text from Gemini Deep Research interaction.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonText(string $text, string $provider): array
    {
        $normalized = trim($text);
        $normalized = preg_replace('/^```(?:json)?\s*/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*```$/', '', $normalized) ?? $normalized;

        if (! str_starts_with($normalized, '{')) {
            $start = strpos($normalized, '{');
            $end = strrpos($normalized, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $normalized = substr($normalized, $start, $end - $start + 1);
            }
        }

        $data = json_decode($normalized, true);

        if (! is_array($data)) {
            throw new RuntimeException("{$provider} response did not contain valid JSON.");
        }

        return $data;
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

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

    private function defaultModelForProvider(string $provider): string
    {
        return match ($provider) {
            'gemini' => (string) config('services.gemini.model', 'gemini-2.5-pro'),
            'gemini_deep_research' => (string) config('services.gemini.deep_research_agent', 'deep-research-preview-04-2026'),
            default => (string) config('services.openai.model', 'gpt-5.5'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function keywordCategorySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'primaryKeyword' => ['type' => 'string'],
                'categoryName' => ['type' => 'string'],
                'categoryUrl' => ['type' => 'string'],
                'categoryMatchReason' => ['type' => 'string'],
            ],
            'required' => ['primaryKeyword', 'categoryName', 'categoryUrl', 'categoryMatchReason'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function batchKeywordCategorySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'targetUrl' => ['type' => 'string'],
                            'primaryKeyword' => ['type' => 'string'],
                            'categoryName' => ['type' => 'string'],
                            'categoryUrl' => ['type' => 'string'],
                            'categoryMatchReason' => ['type' => 'string'],
                        ],
                        'required' => ['targetUrl', 'primaryKeyword', 'categoryName', 'categoryUrl', 'categoryMatchReason'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function onpageSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'auditScore' => ['type' => 'integer'],
                'auditFindings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'auditRecommendations' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'contentRevisionDirection' => ['type' => 'string'],
            ],
            'required' => ['auditScore', 'auditFindings', 'auditRecommendations', 'contentRevisionDirection'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function batchOnpageSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'targetUrl' => ['type' => 'string'],
                            'primaryKeyword' => ['type' => 'string'],
                            'categoryName' => ['type' => 'string'],
                            'categoryUrl' => ['type' => 'string'],
                            'categoryMatchReason' => ['type' => 'string'],
                            'auditScore' => ['type' => 'integer'],
                            'auditFindings' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'auditRecommendations' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'contentRevisionDirection' => ['type' => 'string'],
                        ],
                        'required' => ['targetUrl', 'auditScore', 'auditFindings', 'auditRecommendations', 'contentRevisionDirection'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }
}
