<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->string('step2_ai_provider', 80)->nullable()->after('ai_model');
            $table->string('step3_ai_provider', 80)->nullable()->after('step2_ai_model');
        });
    }

    public function down(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->dropColumn(['step2_ai_provider', 'step3_ai_provider']);
        });
    }
};
