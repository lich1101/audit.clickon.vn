<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndexUrl extends Model
{
    protected $fillable = [
        'property_id',
        'url_exact',
        'url_hash',
        'status',
        'priority',
        'notification_type',
        'claim_token',
        'claimed_at',
        'attempt_count',
        'max_attempts',
        'last_error',
        'last_http_status',
        'inspect_verdict',
        'inspected_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'last_http_status' => 'integer',
            'claimed_at' => 'datetime',
            'inspected_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(IndexProperty::class, 'property_id');
    }
}
