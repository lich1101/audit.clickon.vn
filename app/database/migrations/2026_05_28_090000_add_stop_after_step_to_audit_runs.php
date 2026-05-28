<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('audit_runs', 'stop_after_step')) {
                $table->unsignedTinyInteger('stop_after_step')
                    ->nullable()
                    ->after('callback_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_runs', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('audit_runs', 'stop_after_step') ? 'stop_after_step' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
