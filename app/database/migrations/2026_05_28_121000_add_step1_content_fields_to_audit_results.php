<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_run_items', function (Blueprint $table): void {
            $table->string('content_source', 64)->nullable()->after('extraction_source');
            $table->text('content_error')->nullable()->after('content_source');
        });

        Schema::table('website_audit_url_results', function (Blueprint $table): void {
            $table->text('meta_description')->nullable()->after('page_title');
            $table->string('canonical_url', 2048)->nullable()->after('meta_description');
            $table->json('extracted_headings')->nullable()->after('canonical_url');
            $table->json('extracted_metrics')->nullable()->after('extracted_headings');
            $table->longText('content_excerpt')->nullable()->after('extracted_metrics');
            $table->string('content_source', 64)->nullable()->after('content_excerpt');
            $table->text('content_error')->nullable()->after('content_source');
        });
    }

    public function down(): void
    {
        Schema::table('website_audit_url_results', function (Blueprint $table): void {
            $table->dropColumn([
                'meta_description',
                'canonical_url',
                'extracted_headings',
                'extracted_metrics',
                'content_excerpt',
                'content_source',
                'content_error',
            ]);
        });

        Schema::table('audit_run_items', function (Blueprint $table): void {
            $table->dropColumn([
                'content_source',
                'content_error',
            ]);
        });
    }
};
