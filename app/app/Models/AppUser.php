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
        'password_hash',
        'role',
        'credits',
        'captcha_credits',
        'balance_usd',
        'keyword_rank_prefs',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'captcha_credits' => 'integer',
            'balance_usd' => 'float',
            'keyword_rank_prefs' => 'array',
        ];
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'user_uid', 'firebase_uid');
    }
}
