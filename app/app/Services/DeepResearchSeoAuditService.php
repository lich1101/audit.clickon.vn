<?php

namespace App\Services;

use App\Models\AuditPromptTemplate;
use App\Models\AuditRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeepResearchSeoAuditService
{
    public function __construct(
        private readonly PerplexityResearchService $perplexityResearchService,
        private readonly AuditPromptTemplateService $promptTemplateService,
        private readonly AuditAiStepResponseStorageService $aiStepResponseStorage,
    ) {
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<int, array<string, mixed>>  $categoryContexts
     * @param  array<int, string>  $siteUrls
     * @return array<string, mixed>
     */
    public function analyze(
        array $page,
        array $categories,
        array $categoryContexts,
        array $siteUrls,
        ?string $checklistText,
        ?int $auditRunId = null,
        ?string $stepSuffix = null,
        ?string $primaryKeywordSeed = null,
        ?string $categoryNameSeed = null,
        ?string $categoryUrlSeed = null,
        ?string $researchProvider = null,
        ?string $researchModel = null,
        ?string $reasoningProvider = null,
        ?string $reasoningModel = null,
        ?string $formatterProvider = null,
        ?string $formatterModel = null,
    ): array {
        $batch = $this->analyzeBatch(
            batchPages: [[
                'targetUrl' => (string) ($page['url'] ?? ''),
                'page' => $page,
                'primaryKeywordSeed' => $primaryKeywordSeed,
                'categoryNameSeed' => $categoryNameSeed,
                'categoryUrlSeed' => $categoryUrlSeed,
            ]],
            categories: $categories,
            categoryContexts: $categoryContexts,
            siteUrls: $siteUrls,
            checklistText: $checklistText,
            auditRunId: $auditRunId,
            stepSuffix: $stepSuffix,
            researchProvider: $researchProvider,
            researchModel: $researchModel,
            reasoningProvider: $reasoningProvider,
            reasoningModel: $reasoningModel,
            formatterProvider: $formatterProvider,
            formatterModel: $formatterModel,
        );

        $first = $batch['items'][0] ?? [];

        return [
            'primaryKeyword' => $first['primaryKeyword'] ?? '',
            'categoryName' => $first['categoryName'] ?? null,
            'categoryUrl' => $first['categoryUrl'] ?? null,
            'categoryMatchReason' => $first['categoryMatchReason'] ?? null,
            'researchData' => $first['researchData'] ?? [],
            'auditScore' => $first['auditScore'] ?? 0,
            'auditFindings' => $first['auditFindings'] ?? [],
            'auditRecommendations' => $first['auditRecommendations'] ?? [],
            'contentRevisionDirection' => $first['contentRevisionDirection'] ?? '',
            'promptSnapshots' => $batch['promptSnapshots'],
            'usageEvents' => $batch['usageEvents'],
            'modelUsed' => $batch['modelUsed'],
        ];
    }

    /**
     * @param  array<int, array{targetUrl:string, page: array<string, mixed>, primaryKeywordSeed?: string|null, categoryNameSeed?: string|null, categoryUrlSeed?: string|null}>  $batchPages
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<int, array<string, mixed>>  $categoryContexts
     * @param  array<int, string>  $siteUrls
     * @return array{items: array<int, array<string, mixed>>, promptSnapshots: array<string, mixed>, usageEvents: array<int, array<string, mixed>>, modelUsed: array<string, mixed>}
     */
    public function analyzeBatch(
        array $batchPages,
        array $categories,
        array $categoryContexts,
        array $siteUrls,
        ?string $checklistText,
        ?int $auditRunId = null,
        ?string $stepSuffix = null,
        ?string $researchProvider = null,
        ?string $researchModel = null,
        ?string $reasoningProvider = null,
        ?string $reasoningModel = null,
        ?string $formatterProvider = null,
        ?string $formatterModel = null,
    ): array {
        $researchStep = $this->stepKey('deep_research_research', $stepSuffix);
        $auditStep = $this->stepKey('deep_research_audit', $stepSuffix);
        $formatterStep = $this->stepKey('deep_research_json_formatter', $stepSuffix);

        $research = $this->perplexityResearchService->researchBatch(
            batchPages: $batchPages,
            categories: $categories,
            categoryContexts: $categoryContexts,
            siteUrls: $siteUrls,
            checklistText: $checklistText,
            auditRunId: $auditRunId,
            persistStep: $researchStep,
            provider: $researchProvider,
            model: $researchModel,
        );

        $researchItems = array_values(array_filter($research['items'] ?? [], 'is_array'));
        $researchByUrl = collect($researchItems)
            ->keyBy(fn (array $item): string => (string) ($item['targetUrl'] ?? ''));

        $auditRaw = $this->requestBatchAuditRaw(
            batchPages: $batchPages,
            categoryContexts: $categoryContexts,
            siteUrls: $siteUrls,
            checklistText: $checklistText,
            researchItems: $researchItems,
            auditRunId: $auditRunId,
            persistStep: $auditStep,
            provider: $reasoningProvider,
            model: $reasoningModel,
        );

        $formatted = $this->formatBatchAuditJson(
            rawOutput: $auditRaw['rawText'],
            checklistText: trim((string) ($checklistText ?? '')),
            batchPages: $batchPages,
            researchItems: $researchItems,
            auditRunId: $auditRunId,
            persistStep: $formatterStep,
            provider: $formatterProvider,
            model: $formatterModel,
        );
        $formatterResults = [$formatted];
        $formatterFallbackWarning = null;

        try {
            $items = $this->normalizeFinalBatchAudit($formatted['data'], $batchPages, $researchItems);
        } catch (RuntimeException $exception) {
            try {
                $formatted = $this->formatBatchAuditJson(
                    rawOutput: $auditRaw['rawText'],
                    checklistText: trim((string) ($checklistText ?? '')),
                    batchPages: $batchPages,
                    researchItems: $researchItems,
                    auditRunId: $auditRunId,
                    persistStep: $formatterStep.'_repair',
                    provider: $formatterProvider,
                    model: $formatterModel,
                    formatError: $exception->getMessage(),
                    previousFormatterJson: $formatted['data'],
                    attempt: 2,
                );
                $formatterResults[] = $formatted;
                $items = $this->normalizeFinalBatchAudit($formatted['data'], $batchPages, $researchItems);
            } catch (RuntimeException $repairException) {
                $formatterFallbackWarning = $repairException->getMessage();
                $items = $this->fallbackFinalBatchAuditItems($batchPages, $researchItems, $repairException->getMessage());
                $this->persistAiStepResponse($auditRunId, $formatterStep.'_fallback', [
                    'step' => $formatterStep.'_fallback',
                    'stepLabel' => 'Deep Research C: JSON formatter fallback',
                    'status' => 'fallback_generated',
                    'provider' => $this->formatterProvider($formatterProvider),
                    'model' => $this->formatterModel($formatterProvider, $formatterModel),
                    'errorMessage' => $repairException->getMessage(),
                    'parsed' => ['items' => $items],
                    'createdAt' => now()->toIso8601String(),
                ]);
            }
        }

        return [
            'items' => array_map(function (array $item) use ($researchByUrl): array {
                $researchItem = $researchByUrl->get((string) ($item['targetUrl'] ?? ''));

                return array_merge($item, [
                    'researchData' => is_array($researchItem) ? $researchItem : [],
                ]);
            }, $items),
            'promptSnapshots' => [
                'deepResearchResearch' => $research['promptSnapshot'],
                'deepResearchAudit' => $auditRaw['promptSnapshot'],
                'deepResearchFormatter' => $formatted['promptSnapshot'],
                'deepResearchFormatterAttempts' => array_values(array_map(
                    fn (array $result): array => $result['promptSnapshot'],
                    $formatterResults,
                )),
            ],
            'usageEvents' => [
                ...$this->researchUsageEvents($research, $researchStep),
                array_merge($auditRaw['usage'], ['step' => $auditStep]),
                ...array_values(array_map(
                    fn (array $result): array => array_merge($result['usage'], [
                        'step' => (string) ($result['promptSnapshot']['persistStep'] ?? $formatterStep),
                    ]),
                    $formatterResults,
                )),
            ],
            'modelUsed' => [
                'research' => [
                    'provider' => $research['usage']['provider'] ?? $this->researchProvider($researchProvider),
                    'model' => $research['usage']['model'] ?? $this->researchModel($researchProvider, $researchModel),
                ],
                'reasoning' => [
                    'provider' => $auditRaw['usage']['provider'] ?? $this->reasoningProvider($reasoningProvider),
                    'model' => $auditRaw['usage']['model'] ?? $this->reasoningModel($reasoningProvider, $reasoningModel),
                ],
                'formatter' => [
                    'provider' => $this->formatterProvider($formatterProvider),
                    'model' => $formatted['usage']['model'] ?? $this->formatterModel($formatterProvider, $formatterModel),
                    'fallbackWarning' => $formatterFallbackWarning,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $research
     * @return array<int, array<string, mixed>>
     */
    private function researchUsageEvents(array $research, string $researchStep): array
    {
        $events = array_values(array_filter($research['usageEvents'] ?? [], 'is_array'));

        if ($events !== []) {
            return $events;
        }

        return [array_merge($research['usage'] ?? [], ['step' => $researchStep])];
    }

    /**
     * @param  array<int, array{targetUrl:string, page: array<string, mixed>, primaryKeywordSeed?: string|null, categoryNameSeed?: string|null, categoryUrlSeed?: string|null}>  $batchPages
     * @param  array<int, array<string, mixed>>  $categoryContexts
     * @param  array<int, string>  $siteUrls
     * @param  array<int, array<string, mixed>>  $researchItems
     * @return array{rawText: string, usage: array<string, mixed>, promptSnapshot: array<string, mixed>}
     */
    private function requestBatchAuditRaw(
        array $batchPages,
        array $categoryContexts,
        array $siteUrls,
        ?string $checklistText,
        array $researchItems,
        ?int $auditRunId,
        string $persistStep,
        ?string $provider = null,
        ?string $model = null,
    ): array {
        $provider = $this->reasoningProvider($provider);
        $model = $this->reasoningModel($provider, $model);
        $prompts = $this->promptTemplateService->render(AuditPromptTemplate::STEP_DEEP_RESEARCH_AUDIT, [
            'website_url' => $batchPages[0]['page']['websiteUrl'] ?? '',
            'target_urls_json' => array_values(array_map(
                fn (array $entry): string => (string) ($entry['targetUrl'] ?? ''),
                $batchPages,
            )),
            'batch_pages_json' => array_map(function (array $entry): array {
                $page = is_array($entry['page'] ?? null) ? $entry['page'] : [];

                return [
                    'targetUrl' => (string) ($entry['targetUrl'] ?? ''),
                    'page' => $this->pagePayload($page),
                    'articleContent' => (string) ($page['content'] ?? ''),
                ];
            }, $batchPages),
            'research_items_json' => $researchItems,
            'category_contexts_json' => $categoryContexts,
            'site_urls_json' => $siteUrls,
            'checklist' => trim((string) ($checklistText ?? '')),
        ]);

        $raw = $this->requestReasoningRaw(
            provider: $provider,
            model: $model,
            systemPrompt: $prompts['system'],
            userPrompt: $prompts['user'],
            schema: $this->batchFinalAuditSchema(),
            auditRunId: $auditRunId,
            persistStep: $persistStep,
        );

        return [
            'rawText' => $raw['rawText'],
            'usage' => $raw['usage'],
            'promptSnapshot' => [
                'step' => AuditPromptTemplate::STEP_DEEP_RESEARCH_AUDIT,
                'provider' => $provider,
                'model' => $model,
                'systemPrompt' => $prompts['system'],
                'userPrompt' => $prompts['user'],
                'createdAt' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<int, array{targetUrl:string, page: array<string, mixed>, primaryKeywordSeed?: string|null, categoryNameSeed?: string|null, categoryUrlSeed?: string|null}>  $batchPages
     * @param  array<int, array<string, mixed>>  $researchItems
     * @return array{data: array<string, mixed>, usage: array<string, mixed>, rawText: string, promptSnapshot: array<string, mixed>}
     */
    private function formatBatchAuditJson(
        string $rawOutput,
        string $checklistText,
        array $batchPages,
        array $researchItems,
        ?int $auditRunId,
        string $persistStep,
        ?string $provider = null,
        ?string $model = null,
        ?string $formatError = null,
        ?array $previousFormatterJson = null,
        int $attempt = 1,
    ): array {
        $provider = $this->formatterProvider($provider);
        $model = $this->formatterModel($provider, $model);
        $schema = $this->batchFinalAuditSchema();
        $targetUrls = array_values(array_map(
            fn (array $entry): string => (string) ($entry['targetUrl'] ?? ''),
            $batchPages,
        ));
        $batchPagesJson = $this->batchFormatterPagesPayload($batchPages);

        $partialJson = null;

        try {
            $partialJson = $this->decodeJsonText($rawOutput, 'OpenAI');
        } catch (RuntimeException) {
        }

        $prompts = $this->promptTemplateService->render(AuditPromptTemplate::STEP_DEEP_RESEARCH_JSON_FORMATTER, [
            'raw_ai_output' => $rawOutput,
            'checklist' => $checklistText,
            'expected_schema_json' => $schema,
            'partial_json' => $partialJson ?? [],
            'target_urls_json' => $targetUrls,
            'batch_pages_json' => $batchPagesJson,
            'research_items_json' => $researchItems,
            'format_error' => $formatError ?? '',
            'previous_formatter_json' => $previousFormatterJson ?? [],
            'formatter_attempt' => $attempt,
        ]);
        $this->appendBatchFormatterRuntimeContract(
            $prompts,
            $targetUrls,
            $batchPagesJson,
            $researchItems,
            $schema,
            $formatError,
            $previousFormatterJson,
            $attempt,
        );

        $response = $this->requestJson(
            provider: $provider,
            model: $model,
            systemPrompt: $prompts['system'],
            userPrompt: $prompts['user'],
            schema: $schema,
            auditRunId: $auditRunId,
            persistStep: $persistStep,
        );

        return [
            'data' => $response['data'],
            'usage' => $response['usage'],
            'rawText' => $response['rawText'],
            'promptSnapshot' => [
                'step' => AuditPromptTemplate::STEP_DEEP_RESEARCH_JSON_FORMATTER,
                'persistStep' => $persistStep,
                'provider' => $provider,
                'model' => $model,
                'attempt' => $attempt,
                'formatError' => $formatError,
                'systemPrompt' => $prompts['system'],
                'userPrompt' => $prompts['user'],
                'createdAt' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<int, array{targetUrl:string, page: array<string, mixed>, primaryKeywordSeed?: string|null, categoryNameSeed?: string|null, categoryUrlSeed?: string|null}>  $batchPages
     * @return array<int, array<string, mixed>>
     */
    private function batchFormatterPagesPayload(array $batchPages): array
    {
        return array_map(function (array $entry): array {
            $page = is_array($entry['page'] ?? null) ? $entry['page'] : [];

            return [
                'targetUrl' => (string) ($entry['targetUrl'] ?? ''),
                'primaryKeyword' => (string) ($entry['primaryKeywordSeed'] ?? ''),
                'categoryName' => (string) ($entry['categoryNameSeed'] ?? ''),
                'categoryUrl' => (string) ($entry['categoryUrlSeed'] ?? ''),
                'page' => [
                    'url' => $page['url'] ?? '',
                    'title' => $page['title'] ?? '',
                    'metaDescription' => $page['metaDescription'] ?? '',
                    'canonicalUrl' => $page['canonicalUrl'] ?? '',
                    'headings' => $page['headings'] ?? [],
                    'metrics' => $page['metrics'] ?? [],
                    'contentExcerpt' => (string) ($page['content'] ?? ''),
                    'source' => $page['source'] ?? null,
                    'extractionError' => $page['extractionError'] ?? null,
                ],
            ];
        }, $batchPages);
    }

    /**
     * @param  array{system:string,user:string}  $prompts
     * @param  array<int, string>  $targetUrls
     * @param  array<int, array<string, mixed>>  $batchPages
     * @param  array<int, array<string, mixed>>  $researchItems
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>|null  $previousFormatterJson
     */
    private function appendBatchFormatterRuntimeContract(
        array &$prompts,
        array $targetUrls,
        array $batchPages,
        array $researchItems,
        array $schema,
        ?string $formatError,
        ?array $previousFormatterJson,
        int $attempt,
    ): void {
        $contract = implode("\n", [
            '=== RUNTIME BATCH JSON FORMATTER CONTRACT — AUTHORITATIVE ===',
            'Mode: Deep Research step 3C JSON formatter for one chunk.',
            'Return exactly one valid JSON object. Do not return markdown or prose.',
            'Top-level shape must be: {"items":[...]}',
            'The items array must contain exactly '.count($targetUrls).' item(s), one for each targetUrl.',
            'Keep every targetUrl exactly as provided; do not merge URLs and do not skip URLs.',
            'For each item, preserve or infer primaryKeyword/categoryName/categoryUrl from batch_pages_json and research_items_json when raw reasoning output is incomplete.',
            'For each item, output auditScore, auditFindings, auditRecommendations, contentRevisionDirection according to the schema and Clickon checklist.',
            'auditFindings[0] must be "Điểm kỹ thuật SEO: X/24".',
            'auditFindings[1] must be "Điểm nội dung: Y/6".',
            'contentRevisionDirection must start with Viết lại, Audit Content, Giữ nguyên, or Redirect.',
            'Formatter attempt: '.$attempt,
            $formatError ? 'Previous validation error: '.$formatError : 'Previous validation error: none',
        ]);

        $context = implode("\n\n", [
            $contract,
            'Target URLs JSON:',
            json_encode($targetUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Batch pages JSON:',
            json_encode($batchPages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Research items JSON:',
            json_encode($researchItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Previous formatter JSON, if any:',
            json_encode($previousFormatterJson ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'Expected schema JSON:',
            json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);

        $prompts['system'] .= "\n\n".$contract;
        $prompts['user'] .= "\n\n".$context;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<int, array<string, mixed>>  $categoryContexts
     * @param  array<int, string>  $siteUrls
     * @param  array<string, mixed>  $researchData
     * @return array{rawText: string, usage: array<string, mixed>, promptSnapshot: array<string, mixed>}
     */
    private function requestAuditRaw(
        array $page,
        array $categoryContexts,
        array $siteUrls,
        ?string $checklistText,
        array $researchData,
        string $primaryKeyword,
        ?string $categoryName,
        ?string $categoryUrl,
        ?string $categoryMatchReason,
        ?int $auditRunId,
        string $persistStep,
        ?string $provider = null,
    ): array {
        $provider = $this->reasoningProvider($provider);
        $model = $this->reasoningModel($provider);
        $prompts = $this->promptTemplateService->render(AuditPromptTemplate::STEP_DEEP_RESEARCH_AUDIT, [
            'website_url' => $page['websiteUrl'] ?? '',
            'url' => $page['url'] ?? '',
            'primary_keyword' => $primaryKeyword,
            'category_json' => [
                'categoryName' => $categoryName,
                'categoryUrl' => $categoryUrl,
                'categoryMatchReason' => $categoryMatchReason,
            ],
            'page_json' => $this->pagePayload($page),
            'article_content' => (string) ($page['content'] ?? ''),
            'category_contexts_json' => $categoryContexts,
            'research_json' => $researchData,
            'site_urls_json' => $siteUrls,
            'checklist' => trim((string) ($checklistText ?? '')),
        ]);

        $raw = $this->requestReasoningRaw(
            provider: $provider,
            model: $model,
            systemPrompt: $prompts['system'],
            userPrompt: $prompts['user'],
            schema: $this->finalAuditSchema(),
            auditRunId: $auditRunId,
            persistStep: $persistStep,
        );

        return [
            'rawText' => $raw['rawText'],
            'usage' => $raw['usage'],
            'promptSnapshot' => [
                'step' => AuditPromptTemplate::STEP_DEEP_RESEARCH_AUDIT,
                'provider' => $provider,
                'model' => $model,
                'systemPrompt' => $prompts['system'],
                'userPrompt' => $prompts['user'],
                'createdAt' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, usage: array<string, mixed>, promptSnapshot: array<string, mixed>}
     */
    private function formatAuditJson(
        string $rawOutput,
        string $checklistText,
        ?int $auditRunId,
        string $persistStep,
    ): array {
        $provider = $this->formatterProvider();
        $model = $this->formatterModel();
        $schema = $this->finalAuditSchema();

        $partialJson = null;

        try {
            $partialJson = $this->decodeJsonText($rawOutput, 'OpenAI');
        } catch (RuntimeException) {
        }

        $prompts = $this->promptTemplateService->render(AuditPromptTemplate::STEP_DEEP_RESEARCH_JSON_FORMATTER, [
            'raw_ai_output' => $rawOutput,
            'checklist' => $checklistText,
            'expected_schema_json' => $schema,
            'partial_json' => $partialJson ?? [],
        ]);

        $response = $this->requestJson(
            provider: $provider,
            model: $model,
            systemPrompt: $prompts['system'],
            userPrompt: $prompts['user'],
            schema: $schema,
            auditRunId: $auditRunId,
            persistStep: $persistStep,
        );

        return [
            'data' => $response['data'],
            'usage' => $response['usage'],
            'promptSnapshot' => [
                'step' => AuditPromptTemplate::STEP_DEEP_RESEARCH_JSON_FORMATTER,
                'provider' => $provider,
                'model' => $model,
                'systemPrompt' => $prompts['system'],
                'userPrompt' => $prompts['user'],
                'createdAt' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{auditScore:int, auditFindings: array<int, string>, auditRecommendations: array<int, string>, contentRevisionDirection:string}
     */
    private function normalizeFinalAudit(array $data): array
    {
        $auditScore = max(0, min(100, (int) ($data['auditScore'] ?? 0)));
        $auditFindings = $this->normalizeStringList($data['auditFindings'] ?? []);
        $auditRecommendations = $this->normalizeStringList($data['auditRecommendations'] ?? []);
        $contentRevisionDirection = trim((string) ($data['contentRevisionDirection'] ?? ''));

        if (count($auditFindings) < 4 || count($auditFindings) > 8) {
            throw new RuntimeException('Deep research formatter trả auditFindings ngoài giới hạn 4-8 dòng.');
        }

        if (count($auditRecommendations) < 4 || count($auditRecommendations) > 8) {
            throw new RuntimeException('Deep research formatter trả auditRecommendations ngoài giới hạn 4-8 dòng.');
        }

        if (! preg_match('/^Điểm kỹ thuật SEO:\s*\d+(?:\.\d+)?\/24$/u', $auditFindings[0] ?? '')) {
            throw new RuntimeException('Deep research formatter thiếu dòng "Điểm kỹ thuật SEO: X/24".');
        }

        if (! preg_match('/^Điểm nội dung:\s*\d+(?:\.\d+)?\/6$/u', $auditFindings[1] ?? '')) {
            throw new RuntimeException('Deep research formatter thiếu dòng "Điểm nội dung: Y/6".');
        }

        if (! preg_match('/^(Viết lại|Audit Content|Giữ nguyên|Redirect)\b/u', $contentRevisionDirection)) {
            throw new RuntimeException('Deep research formatter trả contentRevisionDirection sai prefix nghiệp vụ.');
        }

        $sentenceCount = $this->sentenceCount($contentRevisionDirection);

        if ($sentenceCount < 3 || $sentenceCount > 5) {
            throw new RuntimeException('Deep research formatter trả contentRevisionDirection không nằm trong 3-5 câu.');
        }

        return [
            'auditScore' => $auditScore,
            'auditFindings' => $auditFindings,
            'auditRecommendations' => $auditRecommendations,
            'contentRevisionDirection' => $contentRevisionDirection,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{targetUrl:string, page: array<string, mixed>, primaryKeywordSeed?: string|null, categoryNameSeed?: string|null, categoryUrlSeed?: string|null}>  $batchPages
     * @param  array<int, array<string, mixed>>  $researchItems
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFinalBatchAudit(array $data, array $batchPages, array $researchItems): array
    {
        $rawItems = array_values(array_filter(
            is_array($data['items'] ?? null) ? $data['items'] : [],
            'is_array',
        ));

        if ($rawItems === []) {
            throw new RuntimeException('Deep research JSON không có trường items hợp lệ.');
        }

        if (count($rawItems) !== count($batchPages)) {
            throw new RuntimeException(sprintf(
                'Deep research JSON thiếu dòng kết quả: cần %d, nhận %d.',
                count($batchPages),
                count($rawItems),
            ));
        }

        $itemsByUrl = collect($rawItems)
            ->filter(fn (mixed $item): bool => is_array($item) && trim((string) ($item['targetUrl'] ?? '')) !== '')
            ->keyBy(fn (array $item): string => trim((string) $item['targetUrl']));
        $researchByUrl = collect($researchItems)
            ->filter(fn (mixed $item): bool => is_array($item) && trim((string) ($item['targetUrl'] ?? '')) !== '')
            ->keyBy(fn (array $item): string => trim((string) $item['targetUrl']));

        $normalized = [];

        foreach ($batchPages as $index => $entry) {
            $targetUrl = trim((string) ($entry['targetUrl'] ?? ''));
            $rawItem = $itemsByUrl->get($targetUrl) ?? $rawItems[$index] ?? null;
            $researchItem = $researchByUrl->get($targetUrl);

            if (! is_array($rawItem)) {
                throw new RuntimeException(sprintf(
                    'Deep research JSON thiếu dòng kết quả cho URL %s.',
                    $targetUrl,
                ));
            }

            $audit = $this->normalizeFinalAudit($rawItem);

            $normalized[] = array_merge($audit, [
                'targetUrl' => $targetUrl,
                'primaryKeyword' => $this->stringOrNull($rawItem['primaryKeyword'] ?? $researchItem['primaryKeyword'] ?? $entry['primaryKeywordSeed'] ?? null) ?? '',
                'categoryName' => $this->stringOrNull($rawItem['categoryName'] ?? $researchItem['categoryName'] ?? $entry['categoryNameSeed'] ?? null),
                'categoryUrl' => $this->stringOrNull($rawItem['categoryUrl'] ?? $researchItem['categoryUrl'] ?? $entry['categoryUrlSeed'] ?? null),
                'categoryMatchReason' => $this->stringOrNull($rawItem['categoryMatchReason'] ?? $researchItem['categoryMatchReason'] ?? null),
            ]);
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{targetUrl:string, page: array<string, mixed>, primaryKeywordSeed?: string|null, categoryNameSeed?: string|null, categoryUrlSeed?: string|null}>  $batchPages
     * @param  array<int, array<string, mixed>>  $researchItems
     * @return array<int, array<string, mixed>>
     */
    private function fallbackFinalBatchAuditItems(array $batchPages, array $researchItems, string $reason): array
    {
        $researchByUrl = collect($researchItems)
            ->filter(fn (mixed $item): bool => is_array($item) && trim((string) ($item['targetUrl'] ?? '')) !== '')
            ->keyBy(fn (array $item): string => trim((string) $item['targetUrl']));

        return array_map(function (array $entry) use ($researchByUrl, $reason): array {
            $targetUrl = trim((string) ($entry['targetUrl'] ?? ''));
            $researchItem = $researchByUrl->get($targetUrl);
            $researchItem = is_array($researchItem) ? $researchItem : [];

            return [
                'targetUrl' => $targetUrl,
                'primaryKeyword' => $this->stringOrNull($researchItem['primaryKeyword'] ?? $entry['primaryKeywordSeed'] ?? null) ?? '',
                'categoryName' => $this->stringOrNull($researchItem['categoryName'] ?? $entry['categoryNameSeed'] ?? null),
                'categoryUrl' => $this->stringOrNull($researchItem['categoryUrl'] ?? $entry['categoryUrlSeed'] ?? null),
                'categoryMatchReason' => $this->stringOrNull($researchItem['categoryMatchReason'] ?? null) ?? 'Giữ dữ liệu keyword/danh mục từ bước 2 do formatter không chuẩn hóa được JSON cuối.',
                'auditScore' => 0,
                'auditFindings' => [
                    'Điểm kỹ thuật SEO: 0/24',
                    'Điểm nội dung: 0/6',
                    'STT 1-19: Chưa chuẩn hóa được kết quả kỹ thuật SEO cho URL này từ output AI.',
                    'STT 20-25: Chưa chuẩn hóa được kết quả nội dung cho URL này từ output AI.',
                    'Lỗi formatter: '.mb_substr($reason, 0, 220),
                ],
                'auditRecommendations' => [
                    'Chạy lại riêng URL này hoặc giảm Deep Research URL / batch để tăng độ ổn định JSON.',
                    'Kiểm tra raw response của bước 3B và 3C trong ai_step_responses để xác định trường bị thiếu.',
                    'Đảm bảo prompt bước 3C yêu cầu đủ một item cho mỗi targetUrl.',
                    'Giữ keyword và danh mục từ bước 2 làm dữ liệu đầu vào khi chạy lại bước 3.',
                ],
                'contentRevisionDirection' => 'Audit Content. Hệ thống chưa chuẩn hóa được JSON audit cuối cho URL này sau bước repair. Cần chạy lại với batch nhỏ hơn hoặc kiểm tra raw output AI để lấy kết quả đầy đủ. Ưu tiên không dùng kết quả fallback này làm kết luận SEO cuối cùng.',
            ];
        }, $batchPages);
    }

    /**
     * @return array<string, mixed>
     */
    private function finalAuditSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'auditScore' => ['type' => 'number'],
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
    private function batchFinalAuditSchema(): array
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
                            'auditScore' => ['type' => 'number'],
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
                        'required' => [
                            'targetUrl',
                            'primaryKeyword',
                            'categoryName',
                            'categoryUrl',
                            'categoryMatchReason',
                            'auditScore',
                            'auditFindings',
                            'auditRecommendations',
                            'contentRevisionDirection',
                        ],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }

    private function stepKey(string $base, ?string $suffix): string
    {
        $suffix = trim((string) ($suffix ?? ''));

        return $suffix !== '' ? $base.'_'.$suffix : $base;
    }

    private function researchProvider(?string $override = null): string
    {
        $provider = trim((string) ($override ?? ''));

        if ($provider === '') {
            $provider = (string) config('services.audit.deep_research_research_provider', 'perplexity');
        }

        return in_array($provider, ['perplexity', 'gemini_deep_research'], true) ? $provider : 'perplexity';
    }

    private function researchModel(?string $providerOverride = null, ?string $modelOverride = null): string
    {
        $configured = trim((string) ($modelOverride ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        return $this->researchProvider($providerOverride) === 'gemini_deep_research'
            ? (string) config('services.gemini.deep_research_agent', 'deep-research-pro-preview-12-2025')
            : (string) config('services.audit.deep_research_research_model', config('services.perplexity.model', 'sonar-deep-research'));
    }

    private function reasoningProvider(?string $override = null): string
    {
        $provider = trim((string) ($override ?? ''));

        if ($provider === '') {
            $provider = (string) config('services.audit.deep_research_reasoning_provider', 'openai');
        }

        return in_array($provider, ['openai', 'gemini'], true) ? $provider : 'openai';
    }

    private function reasoningModel(?string $providerOverride = null, ?string $modelOverride = null): string
    {
        $configured = trim((string) ($modelOverride ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        return $this->reasoningProvider($providerOverride) === 'gemini'
            ? (string) config('services.gemini.model', 'gemini-2.5-pro')
            : (string) config('services.audit.deep_research_reasoning_model', config('services.openai.model', 'gpt-5.5'));
    }

    private function formatterProvider(?string $override = null): string
    {
        $provider = trim((string) ($override ?? ''));

        if ($provider === '') {
            $provider = (string) config('services.audit.deep_research_formatter_provider', 'openai');
        }

        return in_array($provider, ['openai', 'gemini'], true) ? $provider : 'openai';
    }

    private function formatterModel(?string $providerOverride = null, ?string $modelOverride = null): string
    {
        $configured = trim((string) ($modelOverride ?? ''));

        if ($configured === '') {
            $configured = trim((string) config('services.audit.deep_research_formatter_model', ''));
        }

        if ($configured !== '') {
            return $configured;
        }

        return $this->formatterProvider($providerOverride) === 'gemini'
            ? 'gemini-2.5-flash'
            : (string) config('services.openai.model', 'gpt-5.5');
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function pagePayload(array $page): array
    {
        return [
            'url' => $page['url'] ?? '',
            'title' => $page['title'] ?? '',
            'metaDescription' => $page['metaDescription'] ?? '',
            'canonicalUrl' => $page['canonicalUrl'] ?? '',
            'headings' => $page['headings'] ?? [],
            'metrics' => $page['metrics'] ?? [],
            'contentExcerpt' => $page['content'] ?? '',
            'source' => $page['source'] ?? null,
            'extractionError' => $page['extractionError'] ?? null,
        ];
    }

    /**
     * @return array{rawText: string, usage: array<string, mixed>}
     */
    private function requestOpenAiRaw(
        string $model,
        string $systemPrompt,
        string $userPrompt,
        ?int $auditRunId,
        string $persistStep,
    ): array {
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

        $this->persistAiRequestSnapshot($auditRunId, $persistStep, [
            'step' => $persistStep,
            'stepLabel' => 'Deep Research B: reasoning audit',
            'provider' => 'openai',
            'model' => $model,
            'systemPrompt' => $systemPrompt,
            'userPrompt' => $userPrompt,
            'createdAt' => now()->toIso8601String(),
        ]);

        $response = $this->sendRequest(
            fn (): Response => Http::withToken($apiKey)
                ->acceptJson()
                ->connectTimeout($this->connectTimeoutSeconds())
                ->timeout($this->timeoutSeconds())
                ->post('https://api.openai.com/v1/responses', $payload),
            'OpenAI'
        );

        $this->throwIfRequestFailed($response, 'OpenAI');
        $body = $response->json();
        $text = $this->extractTextFromOpenAiResponse($body);
        $usageMeta = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $usage = [
            'provider' => 'openai',
            'model' => $model,
            'input_tokens' => (int) ($usageMeta['input_tokens'] ?? $usageMeta['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usageMeta['output_tokens'] ?? $usageMeta['completion_tokens'] ?? 0),
            'total_tokens' => (int) (($usageMeta['input_tokens'] ?? $usageMeta['prompt_tokens'] ?? 0) + ($usageMeta['output_tokens'] ?? $usageMeta['completion_tokens'] ?? 0)),
        ];

        $this->persistAiStepResponse($auditRunId, $persistStep, [
            'step' => $persistStep,
            'stepLabel' => 'Deep Research B: reasoning audit',
            'status' => 'raw',
            'provider' => 'openai',
            'model' => $model,
            'rawText' => $text,
            'usage' => $usage,
            'createdAt' => now()->toIso8601String(),
        ]);

        return [
            'rawText' => $text,
            'usage' => $usage,
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{rawText: string, usage: array<string, mixed>}
     */
    private function requestReasoningRaw(
        string $provider,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        ?int $auditRunId,
        string $persistStep,
    ): array {
        return $provider === 'gemini'
            ? $this->requestGeminiReasoningRaw($model, $systemPrompt, $userPrompt, $schema, $auditRunId, $persistStep)
            : $this->requestOpenAiRaw($model, $systemPrompt, $userPrompt, $auditRunId, $persistStep);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{rawText: string, usage: array<string, mixed>}
     */
    private function requestGeminiReasoningRaw(
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        ?int $auditRunId,
        string $persistStep,
    ): array {
        $this->persistAiRequestSnapshot($auditRunId, $persistStep, [
            'step' => $persistStep,
            'stepLabel' => 'Deep Research B: reasoning audit',
            'provider' => 'gemini',
            'model' => $model,
            'systemPrompt' => $systemPrompt,
            'userPrompt' => $userPrompt,
            'schema' => $schema,
            'createdAt' => now()->toIso8601String(),
        ]);

        $raw = $this->requestGeminiRaw($model, $systemPrompt, $userPrompt, $schema);

        $this->persistAiStepResponse($auditRunId, $persistStep, [
            'step' => $persistStep,
            'stepLabel' => 'Deep Research B: reasoning audit',
            'status' => 'raw',
            'provider' => 'gemini',
            'model' => $model,
            'rawText' => $raw['rawText'],
            'usage' => $raw['usage'],
            'createdAt' => now()->toIso8601String(),
        ]);

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{data: array<string, mixed>, usage: array<string, mixed>, rawText: string}
     */
    private function requestJson(
        string $provider,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        ?int $auditRunId,
        string $persistStep,
    ): array {
        $this->persistAiRequestSnapshot($auditRunId, $persistStep, [
            'step' => $persistStep,
            'stepLabel' => 'Deep Research C: JSON formatter',
            'provider' => $provider,
            'model' => $model,
            'systemPrompt' => $systemPrompt,
            'userPrompt' => $userPrompt,
            'schema' => $schema,
            'createdAt' => now()->toIso8601String(),
        ]);

        $raw = match ($provider) {
            'gemini' => $this->requestGeminiRaw($model, $systemPrompt, $userPrompt, $schema),
            default => $this->requestOpenAiFormatterRaw($model, $systemPrompt, $userPrompt),
        };

        $data = $this->decodeJsonText($raw['rawText'], $provider === 'gemini' ? 'Gemini' : 'OpenAI');

        $this->persistAiStepResponse($auditRunId, $persistStep, [
            'step' => $persistStep,
            'stepLabel' => 'Deep Research C: JSON formatter',
            'status' => 'parsed',
            'provider' => $provider,
            'model' => $model,
            'parsed' => $data,
            'usage' => $raw['usage'],
            'createdAt' => now()->toIso8601String(),
        ]);

        return [
            'data' => $data,
            'usage' => $raw['usage'],
            'rawText' => $raw['rawText'],
        ];
    }

    /**
     * @return array{rawText: string, usage: array<string, mixed>}
     */
    private function requestOpenAiFormatterRaw(string $model, string $systemPrompt, string $userPrompt): array
    {
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $payload = [
            'model' => $model,
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

        $response = $this->sendRequest(
            fn (): Response => Http::withToken($apiKey)
                ->acceptJson()
                ->connectTimeout($this->connectTimeoutSeconds())
                ->timeout($this->timeoutSeconds())
                ->post('https://api.openai.com/v1/responses', $payload),
            'OpenAI'
        );

        $this->throwIfRequestFailed($response, 'OpenAI');
        $body = $response->json();
        $usageMeta = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return [
            'rawText' => $this->extractTextFromOpenAiResponse($body),
            'usage' => [
                'provider' => 'openai',
                'model' => $model,
                'input_tokens' => (int) ($usageMeta['input_tokens'] ?? $usageMeta['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($usageMeta['output_tokens'] ?? $usageMeta['completion_tokens'] ?? 0),
                'total_tokens' => (int) (($usageMeta['input_tokens'] ?? $usageMeta['prompt_tokens'] ?? 0) + ($usageMeta['output_tokens'] ?? $usageMeta['completion_tokens'] ?? 0)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{rawText: string, usage: array<string, mixed>}
     */
    private function requestGeminiRaw(string $model, string $systemPrompt, string $userPrompt, array $schema): array
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

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
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        $response = $this->sendRequest(
            fn (): Response => Http::withHeaders([
                'x-goog-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])
                ->acceptJson()
                ->connectTimeout($this->connectTimeoutSeconds())
                ->timeout($this->timeoutSeconds())
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", $payload),
            'Gemini'
        );

        $this->throwIfRequestFailed($response, 'Gemini');
        $body = $response->json();
        $meta = is_array($body['usageMetadata'] ?? null) ? $body['usageMetadata'] : [];

        return [
            'rawText' => $this->extractTextFromGeminiResponse($body),
            'usage' => [
                'provider' => 'gemini',
                'model' => $model,
                'input_tokens' => (int) ($meta['promptTokenCount'] ?? 0),
                'output_tokens' => (int) ($meta['candidatesTokenCount'] ?? 0),
                'total_tokens' => (int) ($meta['totalTokenCount'] ?? 0),
                'reasoning_tokens' => (int) ($meta['thoughtsTokenCount'] ?? 0),
            ],
        ];
    }

    /**
     * @param  callable(): Response  $callback
     */
    private function sendRequest(callable $callback, string $provider): Response
    {
        $attempts = max(1, (int) config('services.audit.ai_http_retry_attempts', 3));
        $sleepMs = max(0, (int) config('services.audit.ai_http_retry_sleep_ms', 2000));
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $callback();
            } catch (ConnectionException $exception) {
                $lastException = $exception;

                if ($attempt < $attempts) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        throw new RuntimeException($provider.' network error after '.$attempts.' attempts: '.trim((string) $lastException?->getMessage()));
    }

    private function throwIfRequestFailed(Response $response, string $provider): void
    {
        if ($response->successful()) {
            return;
        }

        try {
            $response->throw();
        } catch (RequestException $exception) {
            $payload = $exception->response->json();
            $message = is_array($payload)
                ? (string) ($payload['error']['message'] ?? $payload['message'] ?? json_encode($payload, JSON_UNESCAPED_UNICODE))
                : mb_substr($exception->response->body(), 0, 500);

            if ($exception->response->status() === 429) {
                throw new RuntimeException($provider.' rate limit (429): '.$message, previous: $exception);
            }

            throw new RuntimeException($provider.' API lỗi HTTP '.$exception->response->status().': '.$message, previous: $exception);
        }
    }

    private function timeoutSeconds(): int
    {
        $configured = (int) config('services.audit.ai_http_timeout_seconds', 0);

        return $configured > 0 ? $configured : 180;
    }

    private function connectTimeoutSeconds(): int
    {
        return max(5, (int) config('services.audit.ai_http_connect_timeout_seconds', 30));
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
            throw new RuntimeException($provider.' response did not contain valid JSON.');
        }

        return $data;
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value);
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

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function sentenceCount(string $value): int
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', trim($value)) ?: [];

        return count(array_values(array_filter($parts, fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function persistAiRequestSnapshot(?int $auditRunId, string $step, array $record): void
    {
        $run = $auditRunId ? AuditRun::query()->find($auditRunId) : null;

        if (! $run) {
            return;
        }

        $payload = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (! is_string($payload)) {
            return;
        }

        $responses = is_array($run->ai_step_responses) ? $run->ai_step_responses : [];
        $existing = is_array($responses[$step] ?? null) ? $responses[$step] : [];

        try {
            $stored = $this->aiStepResponseStorage->storeRequest($run->public_id, $step, $payload);
            $responses[$step] = array_merge($existing, [
                'step' => $step,
                'stepLabel' => $record['stepLabel'] ?? $step,
                'provider' => $record['provider'] ?? null,
                'model' => $record['model'] ?? null,
                'requestCreatedAt' => $record['createdAt'] ?? now()->toIso8601String(),
            ], $stored);
        } catch (RuntimeException $exception) {
            $responses[$step] = array_merge($existing, [
                'step' => $step,
                'stepLabel' => $record['stepLabel'] ?? $step,
                'provider' => $record['provider'] ?? null,
                'model' => $record['model'] ?? null,
                'requestStorageError' => $exception->getMessage(),
                'requestPreview' => mb_substr($payload, 0, 4000),
                'requestBytes' => strlen($payload),
            ]);
        }

        $run->forceFill(['ai_step_responses' => $responses])->save();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function persistAiStepResponse(?int $auditRunId, string $step, array $record): void
    {
        $run = $auditRunId ? AuditRun::query()->find($auditRunId) : null;

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
            } catch (RuntimeException $exception) {
                unset($record['rawText']);
                $record['rawTextStorageError'] = $exception->getMessage();
                $record['rawTextPreview'] = mb_substr($rawText, 0, 4000);
                $record['rawTextBytes'] = strlen($rawText);
            }
        }

        $existing = is_array($responses[$step] ?? null) ? $responses[$step] : [];
        $responses[$step] = array_merge($existing, $record);
        $run->forceFill(['ai_step_responses' => $responses])->save();
    }
}
