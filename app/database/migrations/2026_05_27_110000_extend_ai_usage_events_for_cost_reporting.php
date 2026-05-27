<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_usage_events', 'citation_tokens')) {
                $table->unsignedInteger('citation_tokens')->default(0)->after('total_tokens');
            }

            if (! Schema::hasColumn('ai_usage_events', 'reasoning_tokens')) {
                $table->unsignedInteger('reasoning_tokens')->default(0)->after('citation_tokens');
            }

            if (! Schema::hasColumn('ai_usage_events', 'search_queries')) {
                $table->unsignedInteger('search_queries')->default(0)->after('reasoning_tokens');
            }

            if (! Schema::hasColumn('ai_usage_events', 'provider_reported_cost_usd')) {
                $table->decimal('provider_reported_cost_usd', 14, 6)->nullable()->after('search_queries');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table): void {
            $dropColumns = array_values(array_filter([
                Schema::hasColumn('ai_usage_events', 'citation_tokens') ? 'citation_tokens' : null,
                Schema::hasColumn('ai_usage_events', 'reasoning_tokens') ? 'reasoning_tokens' : null,
                Schema::hasColumn('ai_usage_events', 'search_queries') ? 'search_queries' : null,
                Schema::hasColumn('ai_usage_events', 'provider_reported_cost_usd') ? 'provider_reported_cost_usd' : null,
            ]));

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
