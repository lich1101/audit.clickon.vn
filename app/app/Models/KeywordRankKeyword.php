<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KeywordRankKeyword extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'website_id',
        'user_uid',
        'keyword',
        'latest_status',
        'latest_rank',
        'latest_page',
        'latest_url',
        'latest_title',
        'latest_error',
        'latest_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'latest_rank' => 'integer',
            'latest_page' => 'integer',
            'latest_checked_at' => 'datetime',
        ];
    }
}
