<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelPricing extends Model
{
    protected $table = 'ai_model_pricing';

    protected $fillable = [
        'provider',
        'model',
        'label',
        'credits_per_1k_input',
        'credits_per_1k_output',
        'usd_per_1m_input',
        'usd_per_1m_output',
        'usd_per_1m_reasoning',
        'usd_per_1m_citation',
        'usd_per_1k_search_queries',
        'min_credits_per_call',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credits_per_1k_input' => 'float',
            'credits_per_1k_output' => 'float',
            'usd_per_1m_input' => 'float',
            'usd_per_1m_output' => 'float',
            'usd_per_1m_reasoning' => 'float',
            'usd_per_1m_citation' => 'float',
            'usd_per_1k_search_queries' => 'float',
            'min_credits_per_call' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
