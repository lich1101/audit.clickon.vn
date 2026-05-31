<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductRequest extends Model
{
    protected $fillable = [
        'firebase_uid',
        'user_email',
        'product_id',
        'product_name',
        'product_type',
        'price',
        'captcha_credits',
        'balance_usd',
        'credits',
        'status',
        'note',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'captcha_credits' => 'integer',
            'balance_usd' => 'float',
            'credits' => 'integer',
            'approved_at' => 'datetime',
        ];
    }
}
