<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloudflareCostDaily extends Model
{
    protected $table = 'cloudflare_cost_daily';

    protected $fillable = [
        'usage_date',
        'tenant_id',
        'domain_name',
        'environment',
        'outcome',
        'workers_requests_cost_usd',
        'workers_cpu_cost_usd',
        'd1_cost_usd',
        'kv_cost_usd',
        'wae_cost_usd',
        'total_estimated_cost_usd',
        'final_reconciled_cost_usd',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'workers_requests_cost_usd' => 'decimal:6',
            'workers_cpu_cost_usd' => 'decimal:6',
            'd1_cost_usd' => 'decimal:6',
            'kv_cost_usd' => 'decimal:6',
            'wae_cost_usd' => 'decimal:6',
            'total_estimated_cost_usd' => 'decimal:6',
            'final_reconciled_cost_usd' => 'decimal:6',
            'last_synced_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
