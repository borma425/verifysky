<?php

namespace App\Models;

use App\Services\Billing\BillingCycleService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'plan',
        'status',
        'is_vip',
        'login_path',
        'billing_start_at',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'billing_start_at' => 'datetime',
            'is_vip' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function usageCycles(): HasMany
    {
        return $this->hasMany(TenantUsage::class);
    }

    public function cloudflareUsageDaily(): HasMany
    {
        return $this->hasMany(CloudflareUsageDaily::class);
    }

    public function cloudflareCostDaily(): HasMany
    {
        return $this->hasMany(CloudflareCostDaily::class);
    }

    public function latestUsageCycle(): HasOne
    {
        return $this->hasOne(TenantUsage::class)->latestOfMany('cycle_start_at');
    }

    public function currentUsageCycle(?CarbonInterface $at = null): TenantUsage
    {
        return app(BillingCycleService::class)->getOrCreateCurrentCycle($this, $at);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function planGrants(): HasMany
    {
        return $this->hasMany(TenantPlanGrant::class);
    }
}
