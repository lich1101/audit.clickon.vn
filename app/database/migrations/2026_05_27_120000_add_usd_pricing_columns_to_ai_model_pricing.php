<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_model_pricing', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_model_pricing', 'usd_per_1m_input')) {
                $table->decimal('usd_per_1m_input', 12, 6)->nullable()->after('credits_per_1k_output');
            }

            if (! Schema::hasColumn('ai_model_pricing', 'usd_per_1m_output')) {
                $table->decimal('usd_per_1m_output', 12, 6)->nullable()->after('usd_per_1m_input');
            }

            if (! Schema::hasColumn('ai_model_pricing', 'usd_per_1m_reasoning')) {
                $table->decimal('usd_per_1m_reasoning', 12, 6)->nullable()->after('usd_per_1m_output');
            }

            if (! Schema::hasColumn('ai_model_pricing', 'usd_per_1m_citation')) {
                $table->decimal('usd_per_1m_citation', 12, 6)->nullable()->after('usd_per_1m_reasoning');
            }

            if (! Schema::hasColumn('ai_model_pricing', 'usd_per_1k_search_queries')) {
                $table->decimal('usd_per_1k_search_queries', 12, 6)->nullable()->after('usd_per_1m_citation');
            }
        });

        $now = now();

        $rows = [
            [
                'provider' => 'openai',
                'model' => 'gpt-4.1-mini',
                'label' => 'GPT-4.1 Mini',
                'credits_per_1k_input' => 0.40,
                'credits_per_1k_output' => 1.60,
                'usd_per_1m_input' => 0.40,
                'usd_per_1m_output' => 1.60,
                'usd_per_1m_reasoning' => null,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 1,
                'is_active' => true,
            ],
            [
                'provider' => 'openai',
                'model' => 'gpt-4.1',
                'label' => 'GPT-4.1',
                'credits_per_1k_input' => 2.00,
                'credits_per_1k_output' => 8.00,
                'usd_per_1m_input' => 2.00,
                'usd_per_1m_output' => 8.00,
                'usd_per_1m_reasoning' => null,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 2,
                'is_active' => true,
            ],
            [
                'provider' => 'openai',
                'model' => 'gpt-5.4',
                'label' => 'GPT-5.4',
                'credits_per_1k_input' => 2.50,
                'credits_per_1k_output' => 7.50,
                'usd_per_1m_input' => null,
                'usd_per_1m_output' => null,
                'usd_per_1m_reasoning' => null,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 2,
                'is_active' => true,
            ],
            [
                'provider' => 'openai',
                'model' => 'gpt-5.5',
                'label' => 'GPT-5.5',
                'credits_per_1k_input' => 5.00,
                'credits_per_1k_output' => 15.00,
                'usd_per_1m_input' => null,
                'usd_per_1m_output' => null,
                'usd_per_1m_reasoning' => null,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 3,
                'is_active' => true,
            ],
            [
                'provider' => 'openai',
                'model' => 'o3-mini',
                'label' => 'o3-mini',
                'credits_per_1k_input' => 1.10,
                'credits_per_1k_output' => 4.40,
                'usd_per_1m_input' => 1.10,
                'usd_per_1m_output' => 4.40,
                'usd_per_1m_reasoning' => null,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 2,
                'is_active' => true,
            ],
            [
                'provider' => 'gemini',
                'model' => 'gemini-2.5-flash',
                'label' => 'Gemini 2.5 Flash',
                'credits_per_1k_input' => 0.15,
                'credits_per_1k_output' => 0.60,
                'usd_per_1m_input' => 0.30,
                'usd_per_1m_output' => 2.50,
                'usd_per_1m_reasoning' => 2.50,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 1,
                'is_active' => true,
            ],
            [
                'provider' => 'gemini',
                'model' => 'gemini-2.5-pro',
                'label' => 'Gemini 2.5 Pro',
                'credits_per_1k_input' => 1.25,
                'credits_per_1k_output' => 5.00,
                'usd_per_1m_input' => 1.25,
                'usd_per_1m_output' => 10.00,
                'usd_per_1m_reasoning' => 10.00,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 2,
                'is_active' => true,
            ],
            [
                'provider' => 'gemini',
                'model' => 'gemini-3.1-pro-preview',
                'label' => 'Gemini 3.1 Pro Preview',
                'credits_per_1k_input' => 1.25,
                'credits_per_1k_output' => 5.00,
                'usd_per_1m_input' => null,
                'usd_per_1m_output' => null,
                'usd_per_1m_reasoning' => null,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => null,
                'min_credits_per_call' => 2,
                'is_active' => true,
            ],
            [
                'provider' => 'gemini_deep_research',
                'model' => 'deep-research-preview-04-2026',
                'label' => 'Gemini Deep Research',
                'credits_per_1k_input' => 0,
                'credits_per_1k_output' => 0,
                'usd_per_1m_input' => 2.00,
                'usd_per_1m_output' => 12.00,
                'usd_per_1m_reasoning' => 12.00,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => 14.00,
                'min_credits_per_call' => 50,
                'is_active' => true,
            ],
            [
                'provider' => 'gemini_deep_research',
                'model' => 'deep-research-pro-preview-12-2025',
                'label' => 'Gemini Deep Research Pro Preview',
                'credits_per_1k_input' => 0,
                'credits_per_1k_output' => 0,
                'usd_per_1m_input' => 2.00,
                'usd_per_1m_output' => 12.00,
                'usd_per_1m_reasoning' => 12.00,
                'usd_per_1m_citation' => null,
                'usd_per_1k_search_queries' => 14.00,
                'min_credits_per_call' => 50,
                'is_active' => true,
            ],
            [
                'provider' => 'perplexity',
                'model' => 'sonar-deep-research',
                'label' => 'Perplexity Sonar Deep Research',
                'credits_per_1k_input' => 2.00,
                'credits_per_1k_output' => 8.00,
                'usd_per_1m_input' => 2.00,
                'usd_per_1m_output' => 8.00,
                'usd_per_1m_reasoning' => 3.00,
                'usd_per_1m_citation' => 2.00,
                'usd_per_1k_search_queries' => 5.00,
                'min_credits_per_call' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('ai_model_pricing')->updateOrInsert(
                [
                    'provider' => $row['provider'],
                    'model' => $row['model'],
                ],
                [
                    'label' => $row['label'],
                    'credits_per_1k_input' => $row['credits_per_1k_input'],
                    'credits_per_1k_output' => $row['credits_per_1k_output'],
                    'usd_per_1m_input' => $row['usd_per_1m_input'],
                    'usd_per_1m_output' => $row['usd_per_1m_output'],
                    'usd_per_1m_reasoning' => $row['usd_per_1m_reasoning'],
                    'usd_per_1m_citation' => $row['usd_per_1m_citation'],
                    'usd_per_1k_search_queries' => $row['usd_per_1k_search_queries'],
                    'min_credits_per_call' => $row['min_credits_per_call'],
                    'is_active' => $row['is_active'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::table('ai_model_pricing', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('ai_model_pricing', 'usd_per_1m_input') ? 'usd_per_1m_input' : null,
                Schema::hasColumn('ai_model_pricing', 'usd_per_1m_output') ? 'usd_per_1m_output' : null,
                Schema::hasColumn('ai_model_pricing', 'usd_per_1m_reasoning') ? 'usd_per_1m_reasoning' : null,
                Schema::hasColumn('ai_model_pricing', 'usd_per_1m_citation') ? 'usd_per_1m_citation' : null,
                Schema::hasColumn('ai_model_pricing', 'usd_per_1k_search_queries') ? 'usd_per_1k_search_queries' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
