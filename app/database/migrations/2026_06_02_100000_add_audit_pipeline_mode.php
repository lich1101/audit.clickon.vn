<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('audit_runs', 'pipeline_mode')) {
                $table->string('pipeline_mode', 32)->default('standard')->after('workflow');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('audit_runs', 'pipeline_mode')) {
                $table->dropColumn('pipeline_mode');
            }
        });
    }
};
