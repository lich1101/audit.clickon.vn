<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $items = DB::table('audit_run_items as items')
            ->join('audit_runs as runs', 'runs.id', '=', 'items.audit_run_id')
            ->whereIn('items.status', ['completed', 'failed'])
            ->orderByDesc('items.updated_at')
            ->get([
                'items.id as item_id',
                'items.audit_run_id',
                'runs.website_id',
                'items.target_url',
                'items.status',
                'items.page_title',
                'items.primary_keyword',
                'items.category_name',
                'items.category_url',
                'items.category_match_reason',
                'items.audit_score',
                'items.audit_findings',
                'items.audit_recommendations',
                'items.content_revision_direction',
                'items.error_message',
                'runs.ai_provider',
                'runs.ai_model',
                'items.completed_at',
                'items.updated_at',
            ]);

        $seen = [];

        foreach ($items as $item) {
            $key = $item->website_id.'|'.$item->target_url;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            DB::table('website_audit_url_results')->insert([
                'website_id' => $item->website_id,
                'target_url_hash' => hash('sha256', $item->target_url),
                'target_url' => $item->target_url,
                'latest_audit_run_id' => $item->audit_run_id,
                'latest_audit_run_item_id' => $item->item_id,
                'status' => $item->status,
                'page_title' => $item->page_title,
                'primary_keyword' => $item->primary_keyword,
                'category_name' => $item->category_name,
                'category_url' => $item->category_url,
                'category_match_reason' => $item->category_match_reason,
                'audit_score' => $item->audit_score,
                'audit_findings' => $item->audit_findings,
                'audit_recommendations' => $item->audit_recommendations,
                'content_revision_direction' => $item->content_revision_direction,
                'error_message' => $item->error_message,
                'ai_provider' => $item->ai_provider ?? 'openai',
                'ai_model' => $item->ai_model,
                'audited_at' => $item->completed_at ?? $item->updated_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('website_audit_url_results')->truncate();
    }
};
