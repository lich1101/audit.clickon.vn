<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'price',
        'credits',
        'balance_usd',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'credits' => 'integer',
            'balance_usd' => 'float',
            'is_active' => 'boolean',
        ];
    }
}
