<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_users', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_users', 'password_hash')) {
                $table->string('password_hash')->nullable()->after('display_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_users', function (Blueprint $table): void {
            if (Schema::hasColumn('app_users', 'password_hash')) {
                $table->dropColumn('password_hash');
            }
        });
    }
};
