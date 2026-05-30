<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Website extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_uid',
        'name',
        'url',
        'same_day_reaudit_granted_until',
        'same_day_reaudit_granted_by',
    ];

    protected function casts(): array
    {
        return [
            'same_day_reaudit_granted_until' => 'datetime',
        ];
    }

    public function audit(): HasOne
    {
        return $this->hasOne(WebsiteAudit::class, 'website_id', 'id');
    }

    public function activeRun(): HasOne
    {
        return $this->hasOne(AuditRun::class, 'website_id', 'id')
            ->ofMany(['created_at' => 'max'], fn ($query) => $query->whereIn('status', ['queued', 'processing']));
    }
}
