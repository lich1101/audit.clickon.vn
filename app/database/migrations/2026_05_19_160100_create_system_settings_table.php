<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });

        $now = now();

        $defaultAiProvider = env('AUDIT_DEFAULT_AI_PROVIDER', 'openai');
        $defaultFormatterProvider = $defaultAiProvider === 'openai' ? 'openai' : 'gemini';
        $step2AiProvider = env('AUDIT_STEP2_AI_PROVIDER', $defaultAiProvider);
        $step3AiProvider = env('AUDIT_STEP3_AI_PROVIDER', $defaultAiProvider);
        $defaultModelForProvider = function (string $provider): string {
            return match ($provider) {
                'gemini' => env('GEMINI_MODEL', 'gemini-2.5-pro'),
                'gemini_deep_research' => env('GEMINI_DEEP_RESEARCH_AGENT', 'deep-research-pro-preview-12-2025'),
                default => env('OPENAI_MODEL', 'gpt-5.5'),
            };
        };

        DB::table('system_settings')->insert([
            [
                'key' => 'audit',
                'value' => json_encode([
                    'aiProvider' => $defaultAiProvider,
                    'aiModel' => env('AUDIT_DEFAULT_AI_MODEL', $defaultModelForProvider($defaultAiProvider)),
                    'step2AiProvider' => $step2AiProvider,
                    'step2AiModel' => env('AUDIT_STEP2_AI_MODEL', $defaultModelForProvider($step2AiProvider)),
                    'step3AiProvider' => $step3AiProvider,
                    'step3AiModel' => env('AUDIT_STEP3_AI_MODEL', $defaultModelForProvider($step3AiProvider)),
                    'step2FormatterProvider' => env('AUDIT_STEP2_FORMATTER_PROVIDER', $defaultFormatterProvider),
                    'step2FormatterModel' => env('AUDIT_STEP2_FORMATTER_MODEL', $defaultFormatterProvider === 'openai' ? env('OPENAI_MODEL', 'gpt-5.5') : 'gemini-2.5-flash'),
                    'step3FormatterProvider' => env('AUDIT_STEP3_FORMATTER_PROVIDER', $defaultFormatterProvider),
                    'step3FormatterModel' => env('AUDIT_STEP3_FORMATTER_MODEL', $defaultFormatterProvider === 'openai' ? env('OPENAI_MODEL', 'gpt-5.5') : 'gemini-2.5-flash'),
                    'maxParallelItems' => 3,
                    'step2BatchSize' => 60,
                    'step3BatchSize' => 30,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
