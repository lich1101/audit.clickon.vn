<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KeywordRankRunItem extends Model
{
    protected $fillable = [
        'keyword_rank_run_id',
        'keyword_rank_keyword_id',
        'keyword',
        'status',
        'rank',
        'page',
        'matched_url',
        'title',
        'error_message',
        'raw_payload',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'page' => 'integer',
            'raw_payload' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
