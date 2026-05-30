<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyCreditUsd = (float) env('AUDIT_LEGACY_CREDIT_TO_USD', 0.01);

        Schema::table('app_users', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_users', 'balance_usd')) {
                $table->decimal('balance_usd', 14, 6)->default(0)->after('credits');
            }
        });

        Schema::table('credit_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('credit_transactions', 'amount_usd')) {
                $table->decimal('amount_usd', 14, 6)->nullable()->after('amount');
            }

            if (! Schema::hasColumn('credit_transactions', 'balance_before_usd')) {
                $table->decimal('balance_before_usd', 14, 6)->nullable()->after('balance_before');
            }

            if (! Schema::hasColumn('credit_transactions', 'balance_after_usd')) {
                $table->decimal('balance_after_usd', 14, 6)->nullable()->after('balance_after');
            }
        });

        Schema::table('ai_usage_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_usage_events', 'usd_charged')) {
                $table->decimal('usd_charged', 12, 6)->default(0)->after('credits_charged');
            }
        });

        Schema::table('ai_model_pricing', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_model_pricing', 'min_usd_per_call')) {
                $table->decimal('min_usd_per_call', 12, 6)->nullable()->after('min_credits_per_call');
            }
        });

        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'balance_usd')) {
                $table->decimal('balance_usd', 12, 2)->nullable()->after('credits');
            }
        });

        Schema::table('plan_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('plan_requests', 'balance_usd')) {
                $table->decimal('balance_usd', 12, 2)->nullable()->after('credits');
            }
        });

        if (Schema::hasColumn('app_users', 'balance_usd') && Schema::hasColumn('app_users', 'credits')) {
            DB::table('app_users')
                ->where('balance_usd', 0)
                ->where('credits', '>', 0)
                ->update([
                    'balance_usd' => DB::raw(sprintf('ROUND(credits * %F, 6)', $legacyCreditUsd)),
                ]);
        }

        if (Schema::hasColumn('plans', 'balance_usd') && Schema::hasColumn('plans', 'credits')) {
            DB::table('plans')
                ->whereNull('balance_usd')
                ->where('credits', '>', 0)
                ->update([
                    'balance_usd' => DB::raw(sprintf('ROUND(credits * %F, 2)', $legacyCreditUsd)),
                ]);
        }

        if (Schema::hasColumn('ai_model_pricing', 'min_usd_per_call')) {
            DB::table('ai_model_pricing')
                ->whereNull('min_usd_per_call')
                ->where('min_credits_per_call', '>', 0)
                ->update([
                    'min_usd_per_call' => DB::raw(sprintf('ROUND(min_credits_per_call * %F, 6)', $legacyCreditUsd)),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('plan_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('plan_requests', 'balance_usd')) {
                $table->dropColumn('balance_usd');
            }
        });

        Schema::table('plans', function (Blueprint $table): void {
            if (Schema::hasColumn('plans', 'balance_usd')) {
                $table->dropColumn('balance_usd');
            }
        });

        Schema::table('ai_model_pricing', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_model_pricing', 'min_usd_per_call')) {
                $table->dropColumn('min_usd_per_call');
            }
        });

        Schema::table('ai_usage_events', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_usage_events', 'usd_charged')) {
                $table->dropColumn('usd_charged');
            }
        });

        Schema::table('credit_transactions', function (Blueprint $table): void {
            foreach (['amount_usd', 'balance_before_usd', 'balance_after_usd'] as $column) {
                if (Schema::hasColumn('credit_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('app_users', function (Blueprint $table): void {
            if (Schema::hasColumn('app_users', 'balance_usd')) {
                $table->dropColumn('balance_usd');
            }
        });
    }
};
