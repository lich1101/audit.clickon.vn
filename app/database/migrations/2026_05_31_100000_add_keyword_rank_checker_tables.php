<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_users', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_users', 'captcha_credits')) {
                $table->unsignedInteger('captcha_credits')->default(0)->after('credits');
            }
        });

        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'captcha_credits')) {
                $table->unsignedInteger('captcha_credits')->default(0)->after('credits');
            }
        });

        Schema::table('plan_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('plan_requests', 'captcha_credits')) {
                $table->unsignedInteger('captcha_credits')->default(0)->after('credits');
            }
        });

        Schema::create('keyword_rank_keywords', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('website_id', 64)->index();
            $table->string('user_uid')->index();
            $table->string('keyword', 255);
            $table->string('latest_status', 32)->nullable();
            $table->unsignedSmallInteger('latest_rank')->nullable();
            $table->unsignedTinyInteger('latest_page')->nullable();
            $table->text('latest_url')->nullable();
            $table->text('latest_title')->nullable();
            $table->text('latest_error')->nullable();
            $table->timestamp('latest_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'keyword']);
            $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
        });

        Schema::create('keyword_rank_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->string('website_id', 64)->index();
            $table->string('user_uid')->index();
            $table->string('target_domain', 255);
            $table->string('status', 32)->default('queued')->index();
            $table->boolean('captcha_enabled')->default(false);
            $table->unsignedInteger('total_keywords')->default(0);
            $table->unsignedInteger('processed_keywords')->default(0);
            $table->unsignedInteger('completed_keywords')->default(0);
            $table->unsignedInteger('failed_keywords')->default(0);
            $table->unsignedInteger('captcha_solve_attempts')->default(0);
            $table->unsignedInteger('captcha_solve_successes')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
        });

        Schema::create('keyword_rank_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('keyword_rank_run_id')->constrained('keyword_rank_runs')->cascadeOnDelete();
            $table->string('keyword_rank_keyword_id', 64)->nullable()->index();
            $table->string('keyword', 255);
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->unsignedTinyInteger('page')->nullable();
            $table->text('matched_url')->nullable();
            $table->text('title')->nullable();
            $table->text('error_message')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['keyword_rank_run_id', 'keyword_rank_keyword_id'], 'keyword_rank_run_keyword_unique');
            $table->foreign('keyword_rank_keyword_id')->references('id')->on('keyword_rank_keywords')->nullOnDelete();
        });

        Schema::create('captcha_solve_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->string('user_uid')->index();
            $table->foreignId('keyword_rank_run_id')->nullable()->constrained('keyword_rank_runs')->nullOnDelete();
            $table->string('provider', 32)->default('2captcha');
            $table->string('provider_task_id', 64)->nullable()->index();
            $table->string('status', 32)->default('processing')->index();
            $table->text('website_url');
            $table->string('website_key', 255);
            $table->text('recaptcha_data_s_value')->nullable();
            $table->longText('solution_token')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->boolean('charged')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captcha_solve_tasks');
        Schema::dropIfExists('keyword_rank_run_items');
        Schema::dropIfExists('keyword_rank_runs');
        Schema::dropIfExists('keyword_rank_keywords');

        Schema::table('plan_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('plan_requests', 'captcha_credits')) {
                $table->dropColumn('captcha_credits');
            }
        });

        Schema::table('plans', function (Blueprint $table): void {
            if (Schema::hasColumn('plans', 'captcha_credits')) {
                $table->dropColumn('captcha_credits');
            }
        });

        Schema::table('app_users', function (Blueprint $table): void {
            if (Schema::hasColumn('app_users', 'captcha_credits')) {
                $table->dropColumn('captcha_credits');
            }
        });
    }
};
