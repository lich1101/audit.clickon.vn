<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\AuditRun;
use App\Models\KeywordRankKeyword;
use App\Models\KeywordRankRun;
use App\Models\Website;
use App\Models\WebsiteAudit;
use App\Models\WebsiteAuditUrlResult;
use App\Services\WebsiteDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class WebsiteDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_website_removes_related_data(): void
    {
        $user = AppUser::query()->create([
            'firebase_uid' => 'owner-1',
            'email' => 'owner@example.com',
            'role' => 'user',
        ]);

        $website = Website::query()->create([
            'id' => 'website-1',
            'user_uid' => $user->firebase_uid,
            'name' => 'Demo',
            'url' => 'https://example.com',
        ]);

        WebsiteAudit::query()->create([
            'id' => 'audit-1',
            'website_id' => $website->id,
            'user_uid' => $user->firebase_uid,
            'article_urls' => ['https://example.com/a'],
            'categories' => [['name' => 'Cat', 'url' => 'https://example.com/cat']],
        ]);

        $run = AuditRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => $website->id,
            'user_uid' => $user->firebase_uid,
            'status' => 'completed',
            'target_urls' => ['https://example.com/a'],
            'total_urls' => 1,
        ]);

        WebsiteAuditUrlResult::query()->create([
            'website_id' => $website->id,
            'target_url_hash' => hash('sha256', 'https://example.com/a'),
            'target_url' => 'https://example.com/a',
            'latest_audit_run_id' => $run->id,
            'status' => 'completed',
        ]);

        KeywordRankKeyword::query()->create([
            'id' => 'kw-1',
            'website_id' => $website->id,
            'user_uid' => $user->firebase_uid,
            'keyword' => 'seo audit',
        ]);

        app(WebsiteDataService::class)->deleteWebsite($website->id);

        $this->assertDatabaseMissing('websites', ['id' => $website->id]);
        $this->assertDatabaseMissing('website_audits', ['website_id' => $website->id]);
        $this->assertDatabaseMissing('audit_runs', ['website_id' => $website->id]);
        $this->assertDatabaseMissing('website_audit_url_results', ['website_id' => $website->id]);
        $this->assertDatabaseMissing('keyword_rank_keywords', ['website_id' => $website->id]);
    }

    public function test_delete_website_blocks_when_audit_run_is_active(): void
    {
        $website = Website::query()->create([
            'id' => 'website-2',
            'user_uid' => 'owner-2',
            'name' => 'Active',
            'url' => 'https://active.example',
        ]);

        AuditRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => $website->id,
            'user_uid' => 'owner-2',
            'status' => 'processing',
            'target_urls' => ['https://active.example/a'],
            'total_urls' => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('audit run đang chạy');

        app(WebsiteDataService::class)->deleteWebsite($website->id);
    }

    public function test_delete_website_blocks_when_keyword_rank_run_is_active(): void
    {
        $website = Website::query()->create([
            'id' => 'website-3',
            'user_uid' => 'owner-3',
            'name' => 'KR',
            'url' => 'https://kr.example',
        ]);

        KeywordRankRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'website_id' => $website->id,
            'user_uid' => 'owner-3',
            'target_domain' => 'kr.example',
            'status' => 'queued',
            'total_keywords' => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('thứ hạng keyword');

        app(WebsiteDataService::class)->deleteWebsite($website->id);
    }
}
