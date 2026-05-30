<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'same_day_reaudit_granted_until')) {
                $table->dateTime('same_day_reaudit_granted_until')->nullable()->after('url');
            }

            if (! Schema::hasColumn('websites', 'same_day_reaudit_granted_by')) {
                $table->string('same_day_reaudit_granted_by')->nullable()->after('same_day_reaudit_granted_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            foreach (['same_day_reaudit_granted_until', 'same_day_reaudit_granted_by'] as $column) {
                if (Schema::hasColumn('websites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
