<?php

namespace Tests\Feature;

use App\Services\KeywordRankProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeywordRankProxyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_for_run_returns_disabled_when_admin_turns_off_proxy(): void
    {
        $service = app(KeywordRankProxyService::class);
        $service->updateAdminConfig(['enabled' => false]);

        $result = $service->resolveForRun();

        $this->assertFalse($result['proxyEnabled']);
        $this->assertSame([], $result['proxyUrls']);
    }

    public function test_resolve_for_run_uses_manual_proxies_only(): void
    {
        $service = app(KeywordRankProxyService::class);
        $service->updateAdminConfig([
            'enabled' => true,
            'useGithubHttp' => false,
            'useGithubSocks5' => false,
            'manualProxiesText' => "1.2.3.4:8080\nsocks5://9.10.11.12:1080\n",
            'runSampleSize' => 10,
        ]);

        $result = $service->resolveForRun();

        $this->assertTrue($result['proxyEnabled']);
        $this->assertGreaterThanOrEqual(2, $result['runProxyCount']);
        $this->assertSame(2, $result['manualCount']);
        $this->assertStringStartsWith('http://', $result['proxyUrls'][0]);
    }

    public function test_refresh_github_pool_fetches_selected_sources(): void
    {
        Http::fake([
            KeywordRankProxyService::HTTP_SOURCE_URL => Http::response("1.2.3.4:8080\n5.6.7.8:3128\n", 200),
            KeywordRankProxyService::SOCKS5_SOURCE_URL => Http::response("9.10.11.12:1080\n", 200),
        ]);

        $service = app(KeywordRankProxyService::class);
        $service->updateAdminConfig([
            'enabled' => true,
            'useGithubHttp' => true,
            'useGithubSocks5' => true,
        ]);

        $pool = $service->refreshGithubPool(true, true);

        $this->assertSame(2, $pool['httpCount']);
        $this->assertSame(1, $pool['socks5Count']);
        $this->assertGreaterThanOrEqual(3, $pool['totalCount']);
    }

    public function test_user_preferences_do_not_persist_proxy_fields(): void
    {
        $user = \App\Models\AppUser::query()->create([
            'firebase_uid' => 'test-proxy-prefs-uid',
            'email' => 'proxy-test@example.com',
            'keyword_rank_prefs' => [
                'delayMin' => 4,
                'proxyEnabled' => true,
                'proxyUrls' => ['http://evil.example:9999'],
            ],
        ]);

        $prefs = app(\App\Services\KeywordRankService::class)->getUserPreferences($user->firebase_uid);

        $this->assertSame(4, $prefs['delayMin']);
        $this->assertArrayNotHasKey('proxyEnabled', $prefs);
        $this->assertArrayNotHasKey('proxyUrls', $prefs);
    }
}
