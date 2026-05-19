<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->string('ai_provider')->default('openai')->after('checklist_text');
            $table->string('ai_model')->nullable()->after('ai_provider');
            $table->json('category_contexts')->nullable()->after('categories');
        });

        Schema::table('audit_run_items', function (Blueprint $table): void {
            $table->string('extraction_source')->nullable()->after('status');
            $table->longText('category_match_reason')->nullable()->after('category_url');
            $table->json('prompt_snapshots')->nullable()->after('content_excerpt');
        });
    }

    public function down(): void
    {
        Schema::table('audit_run_items', function (Blueprint $table): void {
            $table->dropColumn(['extraction_source', 'category_match_reason', 'prompt_snapshots']);
        });

        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->dropColumn(['ai_provider', 'ai_model', 'category_contexts']);
        });
    }
};
