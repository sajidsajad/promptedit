<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'wallet_id',
    'generation_id',
    'type',
    'amount',
    'balance_after',
    'reserved_balance_after',
    'reference',
    'description',
])]
class WalletTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:9',
            'balance_after' => 'decimal:9',
            'reserved_balance_after' => 'decimal:9',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
