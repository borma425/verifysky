<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSubscription extends Model
{
    public const PROVIDER_PAYPAL = 'paypal';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'provider',
        'provider_subscription_id',
        'plan_key',
        'provider_plan_id',
        'status',
        'payer_email',
        'current_period_starts_at',
        'current_period_ends_at',
        'cancel_at_period_end',
        'last_webhook_event_id',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'metadata_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
