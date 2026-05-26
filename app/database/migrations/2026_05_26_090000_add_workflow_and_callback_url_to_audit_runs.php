<?php

use App\Models\AuditRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->string('workflow', 64)
                ->default(AuditRun::WORKFLOW_STANDARD)
                ->after('status');
            $table->string('callback_url', 2048)
                ->nullable()
                ->after('workflow');
        });
    }

    public function down(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $table->dropColumn(['workflow', 'callback_url']);
        });
    }
};
