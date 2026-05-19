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
        'balance_before',
        'balance_after',
        'reason',
        'source',
        'reference_type',
        'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'user_uid', 'firebase_uid');
    }
}
