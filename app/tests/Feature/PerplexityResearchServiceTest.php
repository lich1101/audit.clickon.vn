<?php

namespace Tests\Feature;

use App\Services\PerplexityResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerplexityResearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sonar_deep_research_uses_async_api_with_reasoning_effort_and_json_schema(): void
    {
        config([
            'services.perplexity.api_key' => 'test-perplexity-key',
            'services.perplexity.base_url' => 'https://api.perplexity.test',
            'services.audit.deep_research_research_model' => 'sonar-deep-research',
            'services.audit.deep_research_research_reasoning_effort' => 'high',
            'services.audit.deep_research_research_use_async' => true,
            'services.audit.deep_research_async_timeout_seconds' => 60,
            'services.audit.deep_research_async_poll_interval_ms' => 1,
        ]);

        Http::fake([
            'https://api.perplexity.test/v1/async/sonar' => Http::response([
                'id' => 'async-request-123',
                'model' => 'sonar-deep-research',
                'status' => 'CREATED',
                'created_at' => now()->timestamp,
                'started_at' => null,
                'completed_at' => null,
                'response' => null,
                'failed_at' => null,
                'error_message' => null,
            ], 200),
            'https://api.perplexity.test/v1/async/sonar/async-request-123' => Http::response([
                'id' => 'async-request-123',
                'model' => 'sonar-deep-research',
                'status' => 'COMPLETED',
                'created_at' => now()->timestamp,
                'started_at' => now()->timestamp,
                'completed_at' => now()->timestamp,
                'response' => [
                    'model' => 'sonar-deep-research',
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'items' => [
                                        [
                                            'targetUrl' => 'https://example.com/post-1',
                                            'primaryKeyword' => 'keyword test',
                                            'categoryName' => 'Danh mục test',
                                            'categoryUrl' => 'https://example.com/danh-muc',
                                            'categoryMatchReason' => 'Khớp nội dung',
                                            'searchIntent' => 'Informational',
                                            'competitorInsights' => ['Đối thủ A có bảng so sánh'],
                                            'freshnessInsights' => ['Cập nhật số liệu 2026'],
                                            'keywordDemandEvidence' => 'Có nhu cầu tìm kiếm ổn định',
                                            'contentGapInsights' => ['Thiếu section FAQ'],
                                            'recommendedAngles' => ['Bổ sung case study'],
                                            'sources' => [
                                                [
                                                    'title' => 'Nguồn 1',
                                                    'url' => 'https://source.example/1',
                                                    'date' => '2026-05-01',
                                                    'snippet' => 'Đoạn mô tả',
                                                ],
                                            ],
                                        ],
                                    ],
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ],
                        ],
                    ],
                    'usage' => [
                        'prompt_tokens' => 120,
                        'completion_tokens' => 80,
                        'total_tokens' => 200,
                        'citation_tokens' => 10,
                        'num_search_queries' => 4,
                        'reasoning_tokens' => 500,
                    ],
                    'citations' => ['https://source.example/1'],
                    'search_results' => [
                        [
                            'title' => 'Nguồn 1',
                            'url' => 'https://source.example/1',
                            'date' => '2026-05-01',
                            'last_updated' => '2026-05-02',
                            'snippet' => 'Đoạn mô tả',
                            'source' => 'web',
                        ],
                    ],
                ],
                'failed_at' => null,
                'error_message' => null,
            ], 200),
        ]);

        $result = app(PerplexityResearchService::class)->researchBatch(
            batchPages: [[
                'targetUrl' => 'https://example.com/post-1',
                'page' => [
                    'url' => 'https://example.com/post-1',
                    'title' => 'Bài test',
                    'metaDescription' => 'Mô tả test',
                    'canonicalUrl' => 'https://example.com/post-1',
                    'headings' => ['h1' => ['Bài test']],
                    'metrics' => ['wordCount' => 1200],
                    'content' => 'Nội dung test',
                    'websiteUrl' => 'https://example.com',
                ],
                'primaryKeywordSeed' => 'keyword test',
                'categoryNameSeed' => 'Danh mục test',
                'categoryUrlSeed' => 'https://example.com/danh-muc',
            ]],
            categories: [['name' => 'Danh mục test', 'url' => 'https://example.com/danh-muc']],
            categoryContexts: [],
            siteUrls: ['https://example.com/post-1'],
            checklistText: 'Checklist test',
        );

        $this->assertSame('sonar-deep-research', $result['usage']['model']);
        $this->assertSame(500, $result['usage']['reasoning_tokens']);
        $this->assertSame('keyword test', $result['items'][0]['primaryKeyword']);
        $this->assertSame('https://source.example/1', $result['items'][0]['sources'][0]['url']);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.perplexity.test/v1/async/sonar') {
                return false;
            }

            $payload = $request->data();

            return ($payload['request']['model'] ?? null) === 'sonar-deep-research'
                && ($payload['request']['reasoning_effort'] ?? null) === 'high'
                && ($payload['request']['response_format']['type'] ?? null) === 'json_schema';
        });

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.perplexity.test/v1/async/sonar/async-request-123');
    }
}
