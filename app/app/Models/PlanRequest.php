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
            'approved_at' => 'datetime',
        ];
    }
}
