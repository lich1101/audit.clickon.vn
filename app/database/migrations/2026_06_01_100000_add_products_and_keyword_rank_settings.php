<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_users', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_users', 'keyword_rank_prefs')) {
                $table->json('keyword_rank_prefs')->nullable()->after('captcha_credits');
            }
        });

        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table): void {
                $table->string('id', 32)->primary();
                $table->string('name');
                $table->string('type', 32);
                $table->unsignedInteger('price')->default(0);
                $table->unsignedInteger('captcha_credits')->default(0);
                $table->decimal('balance_usd', 12, 2)->default(0);
                $table->unsignedInteger('credits')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_requests')) {
            Schema::create('product_requests', function (Blueprint $table): void {
                $table->id();
                $table->string('firebase_uid');
                $table->string('user_email')->nullable();
                $table->string('product_id', 32);
                $table->string('product_name');
                $table->string('product_type', 32);
                $table->unsignedInteger('price')->default(0);
                $table->unsignedInteger('captcha_credits')->default(0);
                $table->decimal('balance_usd', 12, 2)->default(0);
                $table->unsignedInteger('credits')->default(0);
                $table->string('status', 32)->default('pending');
                $table->text('note')->nullable();
                $table->string('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
            });
        }

        if (! DB::table('system_settings')->where('key', 'keyword_rank')->exists()) {
            DB::table('system_settings')->insert([
                'key' => 'keyword_rank',
                'value' => json_encode([
                    'extensionInstallUrl' => '',
                    'serpPages' => 10,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_requests');
        Schema::dropIfExists('products');

        Schema::table('app_users', function (Blueprint $table): void {
            if (Schema::hasColumn('app_users', 'keyword_rank_prefs')) {
                $table->dropColumn('keyword_rank_prefs');
            }
        });

        DB::table('system_settings')->where('key', 'keyword_rank')->delete();
    }
};
