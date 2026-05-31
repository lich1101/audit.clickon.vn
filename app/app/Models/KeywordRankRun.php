<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordRankRun extends Model
{
    protected $fillable = [
        'public_id',
        'website_id',
        'user_uid',
        'target_domain',
        'status',
        'captcha_enabled',
        'total_keywords',
        'processed_keywords',
        'completed_keywords',
        'failed_keywords',
        'captcha_solve_attempts',
        'captcha_solve_successes',
        'last_error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'captcha_enabled' => 'boolean',
            'total_keywords' => 'integer',
            'processed_keywords' => 'integer',
            'completed_keywords' => 'integer',
            'failed_keywords' => 'integer',
            'captcha_solve_attempts' => 'integer',
            'captcha_solve_successes' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(KeywordRankRunItem::class, 'keyword_rank_run_id');
    }
}
