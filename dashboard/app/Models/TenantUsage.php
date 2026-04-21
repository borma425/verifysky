<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUsage extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PASS_THROUGH = 'pass_through';

    protected $table = 'tenant_usage';

    protected $fillable = [
        'tenant_id',
        'cycle_start_at',
        'cycle_end_at',
        'protected_sessions_used',
        'bot_requests_used',
        'quota_status',
        'last_reconciled_at',
        'usage_warning_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle_start_at' => 'datetime',
            'cycle_end_at' => 'datetime',
            'protected_sessions_used' => 'integer',
            'bot_requests_used' => 'integer',
            'last_reconciled_at' => 'datetime',
            'usage_warning_sent_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
