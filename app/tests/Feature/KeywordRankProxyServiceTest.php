<?php

namespace Tests\Feature;

use App\Services\KeywordRankProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeywordRankProxyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_pool_fetches_http_and_socks5_sources(): void
    {
        Http::fake([
            KeywordRankProxyService::HTTP_SOURCE_URL => Http::response("1.2.3.4:8080\n5.6.7.8:3128\n", 200),
            KeywordRankProxyService::SOCKS5_SOURCE_URL => Http::response("9.10.11.12:1080\n", 200),
        ]);

        $result = app(KeywordRankProxyService::class)->refreshPool(10);

        $this->assertSame(2, $result['httpCount']);
        $this->assertSame(1, $result['socks5Count']);
        $this->assertGreaterThanOrEqual(3, $result['totalCount']);
        $this->assertLessThanOrEqual(10, $result['runProxyCount']);
        $this->assertNotEmpty($result['proxyUrls']);

        $pool = app(KeywordRankProxyService::class)->getPool();
        $this->assertGreaterThanOrEqual(3, $pool['totalCount']);
        $this->assertStringStartsWith('http://', $pool['proxies'][0]);
    }
}
