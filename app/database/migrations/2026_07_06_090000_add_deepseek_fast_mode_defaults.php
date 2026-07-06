<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ($this->pricingRows() as $row) {
            DB::table('ai_model_pricing')->updateOrInsert(
                [
                    'provider' => $row['provider'],
                    'model' => $row['model'],
                ],
                array_merge($row, [
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]),
            );
        }

        $record = DB::table('system_settings')->where('key', 'audit')->first();
        $value = is_string($record?->value ?? null) ? json_decode((string) $record->value, true) : null;
        $value = is_array($value) ? $value : [];

        $value['fastAiProvider'] = 'deepseek';
        $value['fastAiModel'] = 'deepseek-v4-flash';
        $value['fastFormatterProvider'] = 'deepseek';
        $value['fastFormatterModel'] = 'deepseek-v4-flash';

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'audit'],
            [
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
                'created_at' => $record?->created_at ?? $now,
            ],
        );

        Cache::forget('system_settings.audit');
        Cache::flush();
    }

    public function down(): void
    {
        DB::table('ai_model_pricing')
            ->where('provider', 'deepseek')
            ->whereIn('model', ['deepseek-v4-flash', 'deepseek-v4-pro', 'deepseek-chat', 'deepseek-reasoner'])
            ->delete();

        Cache::forget('system_settings.audit');
    }

    /**
     * DeepSeek billing uses official cache-miss input pricing for conservative credit deduction.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pricingRows(): array
    {
        return [
            [
                'provider' => 'deepseek',
                'model' => 'deepseek-v4-flash',
                'label' => 'DeepSeek V4 Flash',
                'credits_per_1k_input' => 0.0140,
                'credits_per_1k_output' => 0.0280,
                'usd_per_1m_input' => 0.14,
                'usd_per_1m_output' => 0.28,
                'usd_per_1m_reasoning' => null,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 0,
                'min_usd_per_call' => null,
            ],
            [
                'provider' => 'deepseek',
                'model' => 'deepseek-v4-pro',
                'label' => 'DeepSeek V4 Pro',
                'credits_per_1k_input' => 0.0435,
                'credits_per_1k_output' => 0.0870,
                'usd_per_1m_input' => 0.435,
                'usd_per_1m_output' => 0.87,
                'usd_per_1m_reasoning' => null,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 0,
                'min_usd_per_call' => null,
            ],
            [
                'provider' => 'deepseek',
                'model' => 'deepseek-chat',
                'label' => 'DeepSeek Chat (V4 Flash alias)',
                'credits_per_1k_input' => 0.0140,
                'credits_per_1k_output' => 0.0280,
                'usd_per_1m_input' => 0.14,
                'usd_per_1m_output' => 0.28,
                'usd_per_1m_reasoning' => null,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 0,
                'min_usd_per_call' => null,
            ],
            [
                'provider' => 'deepseek',
                'model' => 'deepseek-reasoner',
                'label' => 'DeepSeek Reasoner (V4 Flash alias)',
                'credits_per_1k_input' => 0.0140,
                'credits_per_1k_output' => 0.0280,
                'usd_per_1m_input' => 0.14,
                'usd_per_1m_output' => 0.28,
                'usd_per_1m_reasoning' => null,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 0,
                'min_usd_per_call' => null,
            ],
        ];
    }
};
