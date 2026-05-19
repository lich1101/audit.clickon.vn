<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_users', function (Blueprint $table): void {
            $table->id();
            $table->string('firebase_uid')->unique();
            $table->string('email')->index();
            $table->string('display_name')->nullable();
            $table->string('role', 16)->default('user');
            $table->unsignedInteger('credits')->default(0);
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name');
            $table->unsignedInteger('price')->default(0);
            $table->unsignedInteger('credits');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('websites', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('user_uid')->index();
            $table->string('name');
            $table->string('url', 2048);
            $table->timestamps();
        });

        Schema::create('website_audits', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('website_id')->unique();
            $table->string('user_uid')->index();
            $table->json('article_urls');
            $table->json('categories');
            $table->longText('checklist_text')->nullable();
            $table->timestamps();

            $table->foreign('website_id')->references('id')->on('websites')->cascadeOnDelete();
        });

        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->string('user_uid')->index();
            $table->string('type', 16);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('balance_before');
            $table->unsignedInteger('balance_after');
            $table->string('reason');
            $table->string('source', 32)->default('system');
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_model_pricing', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('model', 160);
            $table->string('label')->nullable();
            $table->decimal('credits_per_1k_input', 10, 4)->default(0);
            $table->decimal('credits_per_1k_output', 10, 4)->default(0);
            $table->unsignedInteger('min_credits_per_call')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'model']);
        });

        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audit_run_item_id')->constrained('audit_run_items')->cascadeOnDelete();
            $table->string('step', 64);
            $table->string('provider', 64);
            $table->string('model', 160);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('credits_charged')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('ai_model_pricing')->insert([
            [
                'provider' => 'openai',
                'model' => 'gpt-4.1-mini',
                'label' => 'GPT-4.1 Mini',
                'credits_per_1k_input' => 0.40,
                'credits_per_1k_output' => 1.60,
                'min_credits_per_call' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider' => 'openai',
                'model' => 'gpt-4.1',
                'label' => 'GPT-4.1',
                'credits_per_1k_input' => 2.00,
                'credits_per_1k_output' => 8.00,
                'min_credits_per_call' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider' => 'openai',
                'model' => 'gpt-5.5',
                'label' => 'GPT-5.5',
                'credits_per_1k_input' => 5.00,
                'credits_per_1k_output' => 15.00,
                'min_credits_per_call' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider' => 'openai',
                'model' => 'o3-mini',
                'label' => 'o3-mini',
                'credits_per_1k_input' => 1.10,
                'credits_per_1k_output' => 4.40,
                'min_credits_per_call' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider' => 'gemini',
                'model' => 'gemini-2.5-flash',
                'label' => 'Gemini 2.5 Flash',
                'credits_per_1k_input' => 0.15,
                'credits_per_1k_output' => 0.60,
                'min_credits_per_call' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider' => 'gemini',
                'model' => 'gemini-2.5-pro',
                'label' => 'Gemini 2.5 Pro',
                'credits_per_1k_input' => 1.25,
                'credits_per_1k_output' => 5.00,
                'min_credits_per_call' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider' => 'gemini_deep_research',
                'model' => 'deep-research-preview-04-2026',
                'label' => 'Gemini Deep Research',
                'credits_per_1k_input' => 0,
                'credits_per_1k_output' => 0,
                'min_credits_per_call' => 50,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
        Schema::dropIfExists('ai_model_pricing');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('website_audits');
        Schema::dropIfExists('websites');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('app_users');
    }
};
