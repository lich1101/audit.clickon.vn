<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteAudit extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'website_id',
        'user_uid',
        'article_urls',
        'categories',
        'checklist_text',
    ];

    protected function casts(): array
    {
        return [
            'article_urls' => 'array',
            'categories' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'website_id', 'id');
    }
}
