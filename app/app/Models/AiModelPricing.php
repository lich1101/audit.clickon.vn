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
        'min_credits_per_call',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credits_per_1k_input' => 'float',
            'credits_per_1k_output' => 'float',
            'min_credits_per_call' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
