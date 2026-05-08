<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudflareBillingSnapshot extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'environment',
        'source',
        'resource',
        'currency',
        'amount_usd',
        'usage_quantity',
        'raw_payload',
        'final_reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount_usd' => 'decimal:6',
            'usage_quantity' => 'decimal:6',
            'raw_payload' => 'array',
            'final_reconciled_at' => 'datetime',
        ];
    }
}
