<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    protected $fillable = [
        'public_id',
        'user_uid',
        'type',
        'amount',
        'amount_usd',
        'balance_before',
        'balance_after',
        'balance_before_usd',
        'balance_after_usd',
        'reason',
        'source',
        'reference_type',
        'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'amount_usd' => 'float',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'balance_before_usd' => 'float',
            'balance_after_usd' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_uid', 'firebase_uid');
    }
}
