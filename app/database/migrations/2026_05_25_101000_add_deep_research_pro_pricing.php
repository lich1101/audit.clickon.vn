<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_model_pricing')->updateOrInsert(
            [
                'provider' => 'gemini_deep_research',
                'model' => 'deep-research-pro-preview-12-2025',
            ],
            [
                'label' => 'Gemini Deep Research Pro Preview',
                'credits_per_1k_input' => 0,
                'credits_per_1k_output' => 0,
                'min_credits_per_call' => 50,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('ai_model_pricing')
            ->where('provider', 'gemini_deep_research')
            ->where('model', 'deep-research-pro-preview-12-2025')
            ->delete();
    }
};
