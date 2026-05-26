<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->string('deep_research_research_model', 160)->nullable()->after('step3_formatter_model');
            $table->string('deep_research_reasoning_model', 160)->nullable()->after('deep_research_research_model');
            $table->string('deep_research_formatter_provider', 40)->nullable()->after('deep_research_reasoning_model');
            $table->string('deep_research_formatter_model', 160)->nullable()->after('deep_research_formatter_provider');
        });
    }

    public function down(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'deep_research_research_model',
                'deep_research_reasoning_model',
                'deep_research_formatter_provider',
                'deep_research_formatter_model',
            ]);
        });
    }
};
