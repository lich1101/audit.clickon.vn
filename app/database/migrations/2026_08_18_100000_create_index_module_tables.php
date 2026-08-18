<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('index_properties', function (Blueprint $table): void {
            $table->id();
            $table->string('user_uid')->index();
            $table->string('code', 64);
            $table->string('name');
            $table->string('site_url', 2048);
            $table->string('site_origin', 512)->nullable();
            $table->string('site_host', 255)->nullable()->index();
            $table->string('gsc_property', 2048);
            $table->string('gcp_project_key', 128)->default('default');
            $table->string('sa_json_path', 512)->default('storage/app/google-indexing-sa.json');
            $table->unsignedInteger('daily_publish_quota')->default(200);
            $table->unsignedInteger('daily_inspect_quota')->default(2000);
            $table->boolean('enabled')->default(true);
            $table->boolean('is_owned')->default(false);
            $table->string('permission_level', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_uid', 'code']);
            $table->unique(['user_uid', 'site_host']);
        });

        Schema::create('index_urls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained('index_properties')->cascadeOnDelete();
            $table->string('url_exact', 2048);
            $table->char('url_hash', 64);
            $table->enum('status', [
                'PENDING', 'SENDING', 'SENT', 'FAILED',
                'SKIPPED_INDEXED', 'SKIPPED_QUALITY', 'SKIPPED_MANUAL',
            ])->default('PENDING');
            $table->integer('priority')->default(100);
            $table->enum('notification_type', ['URL_UPDATED', 'URL_DELETED'])->default('URL_UPDATED');
            $table->char('claim_token', 36)->nullable();
            $table->dateTime('claimed_at', 3)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->string('last_error', 1000)->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('inspect_verdict', 128)->nullable();
            $table->dateTime('inspected_at', 3)->nullable();
            $table->dateTime('sent_at', 3)->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'url_hash']);
            $table->index(['property_id', 'status', 'priority', 'id'], 'idx_index_urls_claim');
            $table->index(['status', 'claimed_at'], 'idx_index_urls_sending');
        });

        Schema::create('index_quota_ledger', function (Blueprint $table): void {
            $table->id();
            $table->string('gcp_project_key', 128);
            $table->enum('quota_type', ['PUBLISH', 'INSPECT']);
            $table->date('day_pt');
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['gcp_project_key', 'quota_type', 'day_pt'], 'uq_index_quota');
        });

        Schema::create('index_send_log', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('url_id');
            $table->unsignedBigInteger('property_id');
            $table->string('url_exact', 2048);
            $table->string('notification_type', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response_json')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index('created_at');
        });

        Schema::create('index_job_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('job_name', 64);
            $table->timestamp('started_at', 3)->useCurrent();
            $table->timestamp('finished_at', 3)->nullable();
            $table->boolean('ok')->nullable();
            $table->json('detail_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_job_runs');
        Schema::dropIfExists('index_send_log');
        Schema::dropIfExists('index_quota_ledger');
        Schema::dropIfExists('index_urls');
        Schema::dropIfExists('index_properties');
    }
};
