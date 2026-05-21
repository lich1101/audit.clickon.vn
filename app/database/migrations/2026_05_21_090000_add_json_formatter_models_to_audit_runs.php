<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->string('step2_formatter_provider', 64)->nullable()->after('ai_model');
            $table->string('step2_formatter_model', 160)->nullable()->after('step2_formatter_provider');
            $table->string('step3_formatter_provider', 64)->nullable()->after('step2_formatter_model');
            $table->string('step3_formatter_model', 160)->nullable()->after('step3_formatter_provider');
        });
    }

    public function down(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'step2_formatter_provider',
                'step2_formatter_model',
                'step3_formatter_provider',
                'step3_formatter_model',
            ]);
        });
    }
};
