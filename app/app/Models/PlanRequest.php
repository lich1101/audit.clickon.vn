<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanRequest extends Model
{
    protected $fillable = [
        'firebase_uid',
        'user_email',
        'plan_id',
        'plan_name',
        'price',
        'credits',
        'captcha_credits',
        'balance_usd',
        'status',
        'note',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'credits' => 'integer',
            'captcha_credits' => 'integer',
            'balance_usd' => 'float',
            'approved_at' => 'datetime',
        ];
    }
}
