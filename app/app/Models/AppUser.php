<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppUser extends Model
{
    protected $fillable = [
        'firebase_uid',
        'email',
        'display_name',
        'role',
        'credits',
        'balance_usd',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'balance_usd' => 'float',
        ];
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'user_uid', 'firebase_uid');
    }
}
