<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('system_settings')->where('key', 'keyword_rank_proxy')->exists()) {
            return;
        }

        DB::table('system_settings')->insert([
            'key' => 'keyword_rank_proxy',
            'value' => json_encode([
                'enabled' => false,
                'useGithubHttp' => true,
                'useGithubSocks5' => true,
                'refreshGithubOnRun' => true,
                'manualProxies' => [],
                'runSampleSize' => 120,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'keyword_rank_proxy')->delete();
    }
};
