<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaptchaSolveTask extends Model
{
    protected $fillable = [
        'public_id',
        'user_uid',
        'keyword_rank_run_id',
        'provider',
        'provider_task_id',
        'status',
        'website_url',
        'website_key',
        'recaptcha_data_s_value',
        'solution_token',
        'cost_usd',
        'charged',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'float',
            'charged' => 'boolean',
        ];
    }

    public function keywordRankRun(): BelongsTo
    {
        return $this->belongsTo(KeywordRankRun::class, 'keyword_rank_run_id');
    }
}
