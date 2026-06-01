<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\KeywordRankService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordRankKeywordDedupeTest extends TestCase
{
    use RefreshDatabase;

    public function test_replace_keywords_deduplicates_case_insensitive_and_whitespace(): void
    {
        $website = Website::query()->create([
            'id' => 'website-kw',
            'user_uid' => 'owner-kw',
            'name' => 'Demo',
            'url' => 'https://example.com',
        ]);

        $saved = app(KeywordRankService::class)->replaceKeywords($website, 'owner-kw', [
            'đầu cáp 3m',
            'đầu cáp 3M',
            '  đầu cáp   3m  ',
            'nhà phân phối 3m',
            'Nhà phân phối 3M',
        ]);

        $this->assertCount(2, $saved);
        $this->assertSame(
            ['đầu cáp 3m', 'nhà phân phối 3m'],
            collect($saved)->pluck('keyword')->sort()->values()->all(),
        );
    }
}
