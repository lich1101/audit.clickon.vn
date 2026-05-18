<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_run_items', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('audit_run_id')->constrained('audit_runs')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('target_url', 2048);
            $table->enum('status', ['queued', 'fetching', 'analyzing', 'completed', 'failed'])->default('queued')->index();
            $table->text('page_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('primary_keyword')->nullable();
            $table->string('category_name')->nullable();
            $table->string('category_url', 2048)->nullable();
            $table->unsignedTinyInteger('audit_score')->nullable();
            $table->longText('audit_findings')->nullable();
            $table->longText('audit_recommendations')->nullable();
            $table->longText('content_revision_direction')->nullable();
            $table->json('extracted_headings')->nullable();
            $table->json('extracted_metrics')->nullable();
            $table->longText('content_excerpt')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['audit_run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_run_items');
    }
};
