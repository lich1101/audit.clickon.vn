<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageEvent extends Model
{
    protected $fillable = [
        'audit_run_item_id',
        'step',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'credits_charged',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'credits_charged' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AuditRunItem::class, 'audit_run_item_id');
    }
}
