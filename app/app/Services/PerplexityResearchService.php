<?php

namespace App\Services;

use App\Models\AuditPromptTemplate;
use App\Models\AuditRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PerplexityResearchService
{
    public function __construct(
        private readonly AuditPromptTemplateService $promptTemplateService,
        private readonly AuditAiStepResponseStorageService $aiStepResponseStorage,
    ) {
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<int, array<string, mixed>>  $categoryContexts
     * @param  array<int, string>  $siteUrls
     * @return array{data: array<string, mixed>, usage: array<string, mixed>, promptSnapshot: array<string, mixed>}
     */
    public function research(
        array $page,
        array $categories,
        array $categoryContexts,
        array $siteUrls,
        ?string $checklistText,
        ?int $auditRunId = null,
        ?string $persistStep = null,
        ?string $primaryKeywordSeed = null,
        ?string $categoryNameSeed = null,
        ?string $categoryUrlSeed = null,
        ?string $model = null,
    ): array {
        $analysis = $this->researchBatch(
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
            persistStep: $persistStep,
            model: $model,
        );

        $first = $analysis['items'][0] ?? [];

        return [
            'data' => is_array($first) ? $first : [],
            'usage' => $analysis['usage'],
            'promptSnapshot' => $analysis['promptSnapshot'],
        ];
    }

    /**
     * @param  array<int, array{targetUrl:string, page: array<string, mixed>, primaryKeywordSeed?: string|null, categoryNameSeed?: string|null, categoryUrlSeed?: string|null}>  $batchPages
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<int, array<string, mixed>>  $categoryContexts
     * @param  array<int, string>  $siteUrls
     * @return array{items: array<int, array<string, mixed>>, usage: array<string, mixed>, usageEvents: array<int, array<string, mixed>>, promptSnapshot: array<string, mixed>}
     */
    public function researchBatch(
        array $batchPages,
        array $categories,
        array $categoryContexts,
        array $siteUrls,
        ?string $checklistText,
        ?int $auditRunId = null,
        ?string $persistStep = null,
        ?string $model = null,
    ): array {
        $provider = 'perplexity';
        $model = trim((string) ($model ?? '')) !== ''
            ? trim((string) $model)
            : (string) config('services.audit.deep_research_research_model', config('services.perplexity.model', 'sonar-deep-research'));
        $schema = $this->researchBatchSchema();
        $step = $persistStep ?: 'deep_research_research';
        $targetUrls = array_values(array_map(
            fn (array $entry): string => (string) ($entry['targetUrl'] ?? ''),
            $batchPages,
        ));

        $prompts = $this->promptTemplateService->render(AuditPromptTemplate::STEP_DEEP_RESEARCH_RESEARCH, [
            'website_url' => $batchPages[0]['page']['websiteUrl'] ?? '',
            'target_urls_json' => $targetUrls,
            'batch_size' => count($batchPages),
            'batch_pages_json' => array_map(function (array $entry): array {
                $page = is_array($entry['page'] ?? null) ? $entry['page'] : [];
                $primaryKeyword = trim((string) ($entry['primaryKeywordSeed'] ?? ''));
                $categoryName = trim((string) ($entry['categoryNameSeed'] ?? ''));
                $categoryUrl = trim((string) ($entry['categoryUrlSeed'] ?? ''));

                return [
                    'targetUrl' => (string) ($entry['targetUrl'] ?? ''),
                    'page' => $this->pagePayload($page),
                    'articleContent' => (string) ($page['content'] ?? ''),
                    'primaryKeyword' => $primaryKeyword,
                    'categoryName' => $categoryName,
                    'categoryUrl' => $categoryUrl,
                    'primaryKeywordSeed' => $primaryKeyword,
                    'categoryNameSeed' => $categoryName,
                    'categoryUrlSeed' => $categoryUrl,
                ];
            }, $batchPages),
            'categories_json' => $categories,
            'category_contexts_json' => $categoryContexts,
            'site_urls_json' => $siteUrls,
            'checklist' => trim((string) ($checklistText ?? '')),
        ]);

        $raw = $this->requestResearchRaw(
            model: $model,
            systemPrompt: $prompts['system'],
            userPrompt: $prompts['user'],
            schema: $schema,
            auditRunId: $auditRunId,
            persistStep: $step,
        );

        $usageEvents = [array_merge($raw['usage'], ['step' => $step])];
        $status = 'parsed';
        $parseWarning = null;

        try {
            $data = $this->decodeJsonText($raw['rawText'], 'Perplexity');
            $items = $this->normalizeResearchBatchItems(
                data: $data,
                batchPages: $batchPages,
                searchResults: $raw['searchResults'] ?? [],
                citations: $raw['citations'] ?? [],
            );
        } catch (RuntimeException $exception) {
            $parseWarning = $exception->getMessage();
            $items = $this->fallbackResearchItemsFromRawText(
                rawOutput: $raw['rawText'],
                batchPages: $batchPages,
                searchResults: $raw['searchResults'] ?? [],
                citations: $raw['citations'] ?? [],
            );
            $status = 'raw_text_fallback';
        }

        $this->persistAiStepResponse($auditRunId, $step, [
            'step' => $step,
            'stepLabel' => 'Deep Research A: Perplexity research',
            'status' => $status,
            'provider' => $provider,
            'model' => $model,
            'parsed' => ['items' => $items],
            'parseWarning' => $parseWarning,
            'usage' => $raw['usage'],
            'createdAt' => now()->toIso8601String(),
        ]);

        return [
            'items' => $items,
            'usage' => $raw['usage'],
            'usageEvents' => $usageEvents,
            'promptSnapshot' => [
                'step' => AuditPromptTemplate::STEP_DEEP_RESEARCH_RESEARCH,
                'provider' => $provider,
                'model' => $model,
                'systemPrompt' => $prompts['system'],
                'userPrompt' => $prompts['user'],
                'createdAt' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{rawText: string, usage: array<string, mixed>, citations: array<int, string>, searchResults: array<int, array<string, mixed>>}
     */
    private function requestResearchRaw(
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $schema,
        ?int $auditRunId,
        string $persistStep,
    ): array {
        $apiKey = config('services.perplexity.api_key');

        if (! $apiKey) {
            throw new RuntimeException('PERPLEXITY_API_KEY is not configured.');
        }

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
            'temperature' => 0.1,
            'search_mode' => 'web',
            'search_recency_filter' => 'year',
            'return_related_questions' => false,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'deep_research_research',
                    'schema' => $schema,
                ],
            ],
        ];

        if ($this->supportsReasoningEffort($model)) {
            $payload['reasoning_effort'] = $this->reasoningEffort();
        }

        $this->persistAiRequestSnapshot($auditRunId, $persistStep, [
            'step' => $persistStep,
            'stepLabel' => 'Deep Research A: Perplexity research',
            'provider' => 'perplexity',
            'model' => $model,
            'systemPrompt' => $systemPrompt,
            'userPrompt' => $userPrompt,
            'schema' => $schema,
            'createdAt' => now()->toIso8601String(),
        ]);

        if ($this->shouldUseAsync($model)) {
            return $this->requestResearchRawAsync(
                apiKey: (string) $apiKey,
                model: $model,
                payload: $payload,
                auditRunId: $auditRunId,
                persistStep: $persistStep,
            );
        }

        return $this->requestResearchRawSync(
            apiKey: (string) $apiKey,
            model: $model,
            payload: $payload,
            auditRunId: $auditRunId,
            persistStep: $persistStep,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{rawText: string, usage: array<string, mixed>, citations: array<int, string>, searchResults: array<int, array<string, mixed>>}
     */
    private function requestResearchRawSync(
        string $apiKey,
        string $model,
        array $payload,
        ?int $auditRunId,
        string $persistStep,
    ): array {
        $response = $this->sendRequest(
            fn (): Response => Http::withToken($apiKey)
                ->acceptJson()
                ->connectTimeout($this->connectTimeoutSeconds())
                ->timeout($this->timeoutSeconds())
                ->post($this->sonarEndpoint(), $payload)
        );

        $this->throwIfRequestFailed($response);

        return $this->extractCompletionPayload(
            body: $response->json(),
            model: $model,
            auditRunId: $auditRunId,
            persistStep: $persistStep,
            interactionId: null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{rawText: string, usage: array<string, mixed>, citations: array<int, string>, searchResults: array<int, array<string, mixed>>}
     */
    private function requestResearchRawAsync(
        string $apiKey,
        string $model,
        array $payload,
        ?int $auditRunId,
        string $persistStep,
    ): array {
        $maxAttempts = $this->asyncRetryAttempts();
        $sleepMs = $this->asyncRetrySleepMs();
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $submissionPayload = [
                    'request' => $payload,
                    'idempotency_key' => $this->asyncIdempotencyKey($auditRunId, $persistStep, $model, $attempt),
                ];

                $response = $this->sendRequest(
                    fn (): Response => Http::withToken($apiKey)
                        ->acceptJson()
                        ->connectTimeout($this->connectTimeoutSeconds())
                        ->timeout($this->timeoutSeconds())
                        ->post($this->asyncSonarEndpoint(), $submissionPayload)
                );

                $this->throwIfRequestFailed($response);
                $this->persistAiStepResponse($auditRunId, $persistStep, [
                    'step' => $persistStep,
                    'stepLabel' => 'Deep Research A: Perplexity research',
                    'status' => 'async_submitted',
                    'provider' => 'perplexity',
                    'model' => (string) (Arr::get($response->json(), 'model') ?? $model),
                    'interactionId' => (string) Arr::get($response->json(), 'id', ''),
                    'asyncStatus' => (string) Arr::get($response->json(), 'status', 'CREATED'),
                    'attempt' => $attempt,
                    'createdAt' => now()->toIso8601String(),
                ]);

                $requestId = trim((string) Arr::get($response->json(), 'id', ''));

                if ($requestId === '') {
                    throw new RuntimeException('Perplexity async request did not return request id.');
                }

                $completedBody = $this->pollAsyncCompletion($apiKey, $requestId);

                if (strtoupper((string) ($completedBody['status'] ?? '')) === 'FAILED') {
                    $errorMessage = trim((string) ($completedBody['error_message'] ?? 'Unknown error.'));

                    $this->persistAiStepResponse($auditRunId, $persistStep, [
                        'step' => $persistStep,
                        'stepLabel' => 'Deep Research A: Perplexity research',
                        'status' => 'async_failed',
                        'provider' => 'perplexity',
                        'model' => (string) (Arr::get($response->json(), 'model') ?? $model),
                        'interactionId' => $requestId,
                        'asyncStatus' => 'FAILED',
                        'attempt' => $attempt,
                        'errorMessage' => $errorMessage,
                        'createdAt' => now()->toIso8601String(),
                    ]);

                    throw new RuntimeException(sprintf(
                        'Perplexity async request failed [%s]: %s',
                        $requestId,
                        $errorMessage,
                    ));
                }

                $completionBody = is_array($completedBody['response'] ?? null) ? $completedBody['response'] : null;

                if (! is_array($completionBody)) {
                    throw new RuntimeException(sprintf(
                        'Perplexity async request completed without response payload [%s].',
                        $requestId,
                    ));
                }

                return $this->extractCompletionPayload(
                    body: $completionBody,
                    model: $model,
                    auditRunId: $auditRunId,
                    persistStep: $persistStep,
                    interactionId: $requestId,
                );
            } catch (RuntimeException $exception) {
                $lastException = $exception;

                if ($attempt >= $maxAttempts || ! $this->shouldRetryAsyncException($exception)) {
                    throw $exception;
                }

                $this->persistAiStepResponse($auditRunId, $persistStep, [
                    'step' => $persistStep,
                    'stepLabel' => 'Deep Research A: Perplexity research',
                    'status' => 'async_retrying',
                    'provider' => 'perplexity',
                    'model' => $model,
                    'attempt' => $attempt,
                    'errorMessage' => $exception->getMessage(),
                    'createdAt' => now()->toIso8601String(),
                ]);

                usleep($sleepMs * 1000);
            }
        }

        throw $lastException ?? new RuntimeException('Perplexity async request failed after retries.');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{rawText: string, usage: array<string, mixed>, citations: array<int, string>, searchResults: array<int, array<string, mixed>>}
     */
    private function extractCompletionPayload(
        array $body,
        string $model,
        ?int $auditRunId,
        string $persistStep,
        ?string $interactionId,
    ): array {
        $rawText = (string) Arr::get($body, 'choices.0.message.content', '');

        if (trim($rawText) === '') {
            throw new RuntimeException('Perplexity response did not contain message content.');
        }

        $usageMeta = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $usage = [
            'provider' => 'perplexity',
            'model' => (string) ($body['model'] ?? $model),
            'input_tokens' => (int) ($usageMeta['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usageMeta['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($usageMeta['total_tokens'] ?? 0),
            'citation_tokens' => (int) ($usageMeta['citation_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($usageMeta['reasoning_tokens'] ?? 0),
            'search_queries' => (int) ($usageMeta['num_search_queries'] ?? 0),
        ];

        $searchResults = array_values(array_filter(
            is_array($body['search_results'] ?? null) ? $body['search_results'] : [],
            'is_array',
        ));
        $citations = array_values(array_filter(
            is_array($body['citations'] ?? null) ? $body['citations'] : [],
            'is_string',
        ));

        $this->persistAiStepResponse($auditRunId, $persistStep, [
            'step' => $persistStep,
            'stepLabel' => 'Deep Research A: Perplexity research',
            'status' => 'raw',
            'provider' => 'perplexity',
            'model' => (string) ($body['model'] ?? $model),
            'interactionId' => $interactionId,
            'rawText' => $rawText,
            'usage' => $usage,
            'citations' => $citations,
            'searchResults' => $searchResults,
            'createdAt' => now()->toIso8601String(),
        ]);

        return [
            'rawText' => $rawText,
            'usage' => $usage,
            'citations' => $citations,
            'searchResults' => $searchResults,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pollAsyncCompletion(string $apiKey, string $requestId): array
    {
        $timeoutSeconds = $this->asyncTimeoutSeconds();
        $pollIntervalMs = $this->asyncPollIntervalMs();
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $response = $this->sendRequest(
                fn (): Response => Http::withToken($apiKey)
                    ->acceptJson()
                    ->connectTimeout($this->connectTimeoutSeconds())
                    ->timeout($this->timeoutSeconds())
                    ->get($this->asyncSonarRequestEndpoint($requestId))
            );

            $this->throwIfRequestFailed($response);
            $body = $response->json();
            $status = strtoupper(trim((string) ($body['status'] ?? '')));

            if (in_array($status, ['COMPLETED', 'FAILED'], true)) {
                return is_array($body) ? $body : [];
            }

            usleep($pollIntervalMs * 1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(sprintf(
            'Perplexity async request %s timed out after %d seconds.',
            $requestId,
            $timeoutSeconds,
        ));
    }

    /**
     * @param  array<int, mixed>  $rawSources
     * @param  array<int, array<string, mixed>>  $searchResults
     * @param  array<int, string>  $citations
     * @return array<int, array<string, string|null>>
     */
    private function normalizeSources(array $rawSources, array $searchResults, array $citations): array
    {
        $sources = [];

        foreach ($rawSources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $url = trim((string) ($source['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $sources[$url] = [
                'title' => $this->stringOrNull($source['title'] ?? null),
                'url' => $url,
                'date' => $this->stringOrNull($source['date'] ?? null),
                'snippet' => $this->stringOrNull($source['snippet'] ?? null),
            ];
        }

        foreach ($searchResults as $source) {
            $url = trim((string) ($source['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $sources[$url] = [
                'title' => $this->stringOrNull($source['title'] ?? null),
                'url' => $url,
                'date' => $this->stringOrNull($source['date'] ?? $source['last_updated'] ?? null),
                'snippet' => $this->stringOrNull($source['snippet'] ?? null),
            ];
        }

        foreach ($citations as $url) {
            if (! isset($sources[$url])) {
                $sources[$url] = [
                    'title' => null,
                    'url' => $url,
                    'date' => null,
                    'snippet' => null,
                ];
            }
        }

        return array_values($sources);
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
     * @return array<string, mixed>
     */
    private function researchBatchSchema(): array
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
                            'searchIntent' => ['type' => 'string'],
                            'competitorInsights' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'freshnessInsights' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'keywordDemandEvidence' => ['type' => 'string'],
                            'contentGapInsights' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'recommendedAngles' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'sources' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'title' => ['type' => 'string'],
                                        'url' => ['type' => 'string'],
                                        'date' => ['type' => 'string'],
                                        'snippet' => ['type' => 'string'],
                                    ],
                                    'required' => ['title', 'url', 'date', 'snippet'],
                                ],
                            ],
                        ],
                        'required' => [
                            'targetUrl',
                            'primaryKeyword',
                            'categoryName',
                            'categoryUrl',
                            'categoryMatchReason',
                            'searchIntent',
                            'competitorInsights',
                            'freshnessInsights',
                            'keywordDemandEvidence',
                            'contentGapInsights',
                            'recommendedAngles',
                            'sources',
                        ],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{targetUrl:string, page: array<string, mixed>, primaryKeywordSeed?: string|null, categoryNameSeed?: string|null, categoryUrlSeed?: string|null}>  $batchPages
     * @param  array<int, array<string, mixed>>  $searchResults
     * @param  array<int, string>  $citations
     * @return array<int, array<string, mixed>>
     */
    private function normalizeResearchBatchItems(array $data, array $batchPages, array $searchResults, array $citations): array
    {
        $rawItems = array_values(array_filter(
            is_array($data['items'] ?? null) ? $data['items'] : [],
            'is_array',
        ));

        if ($rawItems === []) {
            throw new RuntimeException('Bước A research JSON không có trường items hợp lệ.');
        }

        if (count($rawItems) !== count($batchPages)) {
            throw new RuntimeException(sprintf(
                'Bước A research JSON thiếu dòng kết quả: cần %d, nhận %d.',
                count($batchPages),
                count($rawItems),
            ));
        }

        $itemsByUrl = collect($rawItems)
            ->filter(fn (mixed $item): bool => is_array($item) && trim((string) ($item['targetUrl'] ?? '')) !== '')
            ->keyBy(fn (array $item): string => trim((string) $item['targetUrl']));

        $normalized = [];

        foreach ($batchPages as $index => $entry) {
            $targetUrl = trim((string) ($entry['targetUrl'] ?? ''));
            $rawItem = $itemsByUrl->get($targetUrl) ?? $rawItems[$index] ?? null;

            if (! is_array($rawItem)) {
                throw new RuntimeException(sprintf(
                    'Bước A research JSON thiếu dòng kết quả cho URL %s.',
                    $targetUrl,
                ));
            }

            $normalized[] = [
                'targetUrl' => $targetUrl,
                'primaryKeyword' => trim((string) ($rawItem['primaryKeyword'] ?? $entry['primaryKeywordSeed'] ?? '')),
                'categoryName' => trim((string) ($rawItem['categoryName'] ?? $entry['categoryNameSeed'] ?? '')),
                'categoryUrl' => trim((string) ($rawItem['categoryUrl'] ?? $entry['categoryUrlSeed'] ?? '')),
                'categoryMatchReason' => trim((string) ($rawItem['categoryMatchReason'] ?? '')),
                'searchIntent' => trim((string) ($rawItem['searchIntent'] ?? '')),
                'competitorInsights' => $this->normalizeStringList($rawItem['competitorInsights'] ?? []),
                'freshnessInsights' => $this->normalizeStringList($rawItem['freshnessInsights'] ?? []),
                'keywordDemandEvidence' => trim((string) ($rawItem['keywordDemandEvidence'] ?? '')),
                'contentGapInsights' => $this->normalizeStringList($rawItem['contentGapInsights'] ?? []),
                'recommendedAngles' => $this->normalizeStringList($rawItem['recommendedAngles'] ?? []),
                'sources' => $this->normalizeSources(
                    is_array($rawItem['sources'] ?? null) ? $rawItem['sources'] : [],
                    $searchResults,
                    $citations,
                ),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{targetUrl:string, page: array<string, mixed>, primaryKeywordSeed?: string|null, categoryNameSeed?: string|null, categoryUrlSeed?: string|null}>  $batchPages
     * @param  array<int, array<string, mixed>>  $searchResults
     * @param  array<int, string>  $citations
     * @return array<int, array<string, mixed>>
     */
    private function fallbackResearchItemsFromRawText(string $rawOutput, array $batchPages, array $searchResults, array $citations): array
    {
        $rawPreview = mb_substr(trim($rawOutput), 0, 8000);

        return array_map(function (array $entry) use ($rawPreview, $searchResults, $citations): array {
            return [
                'targetUrl' => trim((string) ($entry['targetUrl'] ?? '')),
                'primaryKeyword' => trim((string) ($entry['primaryKeywordSeed'] ?? '')),
                'categoryName' => trim((string) ($entry['categoryNameSeed'] ?? '')),
                'categoryUrl' => trim((string) ($entry['categoryUrlSeed'] ?? '')),
                'categoryMatchReason' => 'Dữ liệu lấy từ bước 2; Perplexity trả raw text không parse được JSON.',
                'searchIntent' => 'Cần suy luận từ raw research text.',
                'competitorInsights' => array_values(array_filter([
                    $rawPreview !== '' ? $rawPreview : null,
                ])),
                'freshnessInsights' => [],
                'keywordDemandEvidence' => '',
                'contentGapInsights' => [],
                'recommendedAngles' => [],
                'sources' => $this->normalizeSources([], $searchResults, $citations),
                'rawResearchText' => $rawPreview,
            ];
        }, $batchPages);
    }

    /**
     * @param  array<int, mixed>  $value
     * @return array<int, string>
     */
    private function normalizeStringList(array $value): array
    {
        return array_values(array_filter(
            array_map(
                fn (mixed $item): string => trim((string) $item),
                $value,
            ),
            fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @param  callable(): Response  $callback
     */
    private function sendRequest(callable $callback): Response
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

        throw new RuntimeException('Perplexity network error after '.$attempts.' attempts: '.trim((string) $lastException?->getMessage()));
    }

    private function throwIfRequestFailed(Response $response): void
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
                throw new RuntimeException('Perplexity rate limit (429): '.$message);
            }

            throw new RuntimeException('Perplexity API lỗi HTTP '.$exception->response->status().': '.$message, previous: $exception);
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

    private function supportsReasoningEffort(string $model): bool
    {
        return str_contains(Str::lower($model), 'deep-research');
    }

    private function shouldUseAsync(string $model): bool
    {
        return $this->supportsReasoningEffort($model)
            && (filter_var(config('services.audit.deep_research_research_use_async', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true);
    }

    private function reasoningEffort(): string
    {
        $effort = Str::lower(trim((string) config('services.audit.deep_research_research_reasoning_effort', 'medium')));

        return in_array($effort, ['low', 'medium', 'high'], true) ? $effort : 'medium';
    }

    private function asyncTimeoutSeconds(): int
    {
        return max(60, (int) config('services.audit.deep_research_async_timeout_seconds', 900));
    }

    private function asyncPollIntervalMs(): int
    {
        return max(1000, (int) config('services.audit.deep_research_async_poll_interval_ms', 3000));
    }

    private function sonarEndpoint(): string
    {
        return rtrim((string) config('services.perplexity.base_url', 'https://api.perplexity.ai'), '/').'/v1/sonar';
    }

    private function asyncSonarEndpoint(): string
    {
        return rtrim((string) config('services.perplexity.base_url', 'https://api.perplexity.ai'), '/').'/v1/async/sonar';
    }

    private function asyncSonarRequestEndpoint(string $requestId): string
    {
        return $this->asyncSonarEndpoint().'/'.rawurlencode($requestId);
    }

    private function asyncIdempotencyKey(?int $auditRunId, string $persistStep, string $model, int $attempt = 1): string
    {
        $seed = implode(':', [
            'audit-deep-research',
            (string) ($auditRunId ?? 'standalone'),
            $persistStep,
            $model,
            'attempt-'.$attempt,
        ]);

        return substr(hash('sha256', $seed), 0, 64);
    }

    private function asyncRetryAttempts(): int
    {
        return max(1, (int) config('services.audit.deep_research_async_retry_attempts', 2));
    }

    private function asyncRetrySleepMs(): int
    {
        return max(0, (int) config('services.audit.deep_research_async_retry_sleep_ms', 1500));
    }

    private function shouldRetryAsyncException(RuntimeException $exception): bool
    {
        $message = Str::lower(trim($exception->getMessage()));

        foreach ([
            'internal server error',
            'server error',
            'temporarily unavailable',
            'upstream',
            'timed out',
            'timeout',
            'network error',
            'http 500',
            'http 502',
            'http 503',
            'http 504',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
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

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
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
