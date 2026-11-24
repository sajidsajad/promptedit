<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Generation extends Model
{
    protected $fillable = [
        'user_id',
        'pixelforge_id',
        'idempotency_key',
        'provider_idempotency_key',
        'request_hash',
        'prompt',
        'size',
        'quality',
        'status',
        'reference_images',
        'metadata',
        'pricing_rates',
        'pricing_tokens',
        'estimated_price',
        'usage',
        'provider_cost',
        'customer_price',
        'image_url',
        'image_url_expires_at',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'metadata' => 'array',
            'pricing_rates' => 'array',
            'usage' => 'array',
            'provider_cost' => 'decimal:9',
            'pricing_tokens' => 'array',
            'customer_price' => 'decimal:9',
            'estimated_price' => 'decimal:9',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }
}
