<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloudflareUsageDaily extends Model
{
    protected $table = 'cloudflare_usage_daily';

    protected $fillable = [
        'usage_date',
        'tenant_id',
        'domain_name',
        'environment',
        'outcome',
        'requests',
        'd1_rows_read',
        'd1_rows_written',
        'd1_query_count',
        'kv_reads',
        'kv_writes',
        'kv_deletes',
        'kv_lists',
        'kv_write_bytes',
        'pass_d1_writes',
        'pass_kv_writes',
        'pass_kv_reads',
        'pass_config_cache_hit',
        'pass_config_cache_miss',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'requests' => 'integer',
            'd1_rows_read' => 'integer',
            'd1_rows_written' => 'integer',
            'd1_query_count' => 'integer',
            'kv_reads' => 'integer',
            'kv_writes' => 'integer',
            'kv_deletes' => 'integer',
            'kv_lists' => 'integer',
            'kv_write_bytes' => 'integer',
            'pass_d1_writes' => 'integer',
            'pass_kv_writes' => 'integer',
            'pass_kv_reads' => 'integer',
            'pass_config_cache_hit' => 'integer',
            'pass_config_cache_miss' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
