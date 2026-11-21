<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'balance',
    'reserved_balance',
])]
class Wallet extends Model
{
protected $attributes = [
    'balance' => '0.000000000',
    'reserved_balance' => '0.000000000',
];
    protected function casts(): array
    {
        return [
            'balance' => 'decimal:9',
            'reserved_balance' => 'decimal:9',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
