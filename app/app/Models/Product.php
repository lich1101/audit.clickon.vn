<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const TYPE_CAPTCHA_PACK = 'captcha_pack';

    public const TYPE_AUDIT_CREDIT = 'audit_credit';

    protected $fillable = [
        'id',
        'name',
        'type',
        'price',
        'captcha_credits',
        'balance_usd',
        'credits',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'captcha_credits' => 'integer',
            'balance_usd' => 'float',
            'credits' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
