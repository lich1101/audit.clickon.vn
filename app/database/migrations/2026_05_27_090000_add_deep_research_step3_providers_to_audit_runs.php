<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('audit_runs', 'deep_research_research_provider')) {
                $table->string('deep_research_research_provider', 50)
                    ->nullable()
                    ->after('step3_formatter_model');
            }

            if (! Schema::hasColumn('audit_runs', 'deep_research_reasoning_provider')) {
                $table->string('deep_research_reasoning_provider', 50)
                    ->nullable()
                    ->after('deep_research_research_model');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $dropColumns = array_values(array_filter([
                Schema::hasColumn('audit_runs', 'deep_research_research_provider') ? 'deep_research_research_provider' : null,
                Schema::hasColumn('audit_runs', 'deep_research_reasoning_provider') ? 'deep_research_reasoning_provider' : null,
            ]));

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
