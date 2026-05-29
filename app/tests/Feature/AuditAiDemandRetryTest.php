<?php

namespace Tests\Feature;

use App\Services\SeoAiAuditService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class AuditAiDemandRetryTest extends TestCase
{
    public function test_send_ai_request_retries_high_demand_until_success(): void
    {
        config([
            'services.audit.ai_http_retry_attempts' => 1,
            'services.audit.ai_demand_retry_sleep_ms' => 0,
            'services.audit.ai_demand_retry_max_attempts' => 0,
        ]);

        Http::fake([
            'example.test/*' => Http::sequence()
                ->push([
                    'error' => [
                        'message' => 'This model is currently experiencing high demand. Spikes in demand are usually temporary',
                    ],
                ], 503)
                ->push(['ok' => true], 200),
        ]);

        $service = app(SeoAiAuditService::class);
        $method = new ReflectionMethod($service, 'sendAiRequest');
        $method->setAccessible(true);

        $response = $method->invoke(
            $service,
            fn () => Http::acceptJson()->post('https://example.test/generate', []),
            'Gemini',
        );

        $this->assertTrue($response->successful());
        Http::assertSentCount(2);
    }

    public function test_send_ai_request_stops_on_non_recoverable_error(): void
    {
        config([
            'services.audit.ai_http_retry_attempts' => 1,
            'services.audit.ai_demand_retry_sleep_ms' => 0,
            'services.audit.ai_demand_retry_max_attempts' => 0,
        ]);

        Http::fake([
            'example.test/*' => Http::response(['error' => ['message' => 'API key not valid']], 401),
        ]);

        $service = app(SeoAiAuditService::class);
        $method = new ReflectionMethod($service, 'sendAiRequest');
        $method->setAccessible(true);

        try {
            $method->invoke(
                $service,
                fn () => Http::acceptJson()->post('https://example.test/generate', []),
                'Gemini',
            );
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('401', $exception->getMessage());
        } catch (RequestException) {
            $this->fail('Expected wrapped RuntimeException, not raw RequestException.');
        }

        Http::assertSentCount(1);
    }
}
