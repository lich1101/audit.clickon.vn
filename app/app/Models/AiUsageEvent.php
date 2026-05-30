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
        'citation_tokens',
        'reasoning_tokens',
        'search_queries',
        'provider_reported_cost_usd',
        'credits_charged',
        'usd_charged',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'citation_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'search_queries' => 'integer',
            'provider_reported_cost_usd' => 'float',
            'credits_charged' => 'integer',
            'usd_charged' => 'float',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AuditRunItem::class, 'audit_run_item_id');
    }
}
