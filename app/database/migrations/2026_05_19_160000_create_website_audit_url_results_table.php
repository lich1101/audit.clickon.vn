<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_audit_url_results', function (Blueprint $table): void {
            $table->id();
            $table->string('website_id')->index();
            $table->string('target_url_hash', 64);
            $table->string('target_url', 2048);
            $table->foreignId('latest_audit_run_id')->nullable()->constrained('audit_runs')->nullOnDelete();
            $table->foreignId('latest_audit_run_item_id')->nullable()->constrained('audit_run_items')->nullOnDelete();
            $table->string('status', 32)->default('completed');
            $table->string('page_title')->nullable();
            $table->string('primary_keyword')->nullable();
            $table->string('category_name')->nullable();
            $table->string('category_url', 2048)->nullable();
            $table->text('category_match_reason')->nullable();
            $table->unsignedTinyInteger('audit_score')->nullable();
            $table->longText('audit_findings')->nullable();
            $table->longText('audit_recommendations')->nullable();
            $table->longText('content_revision_direction')->nullable();
            $table->text('error_message')->nullable();
            $table->string('ai_provider', 64)->default('openai');
            $table->string('ai_model')->nullable();
            $table->timestamp('audited_at')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'target_url_hash'], 'website_audit_url_results_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_audit_url_results');
    }
};
