<?php

namespace App\Services\Billing;

use App\Models\PaymentWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class TenantSubscriptionService
{
    private ?bool $storageReady = null;

    public function storageReady(): bool
    {
        return $this->storageReady ??= Schema::hasTable('tenant_subscriptions')
            && Schema::hasTable('payment_webhook_events')
            && Schema::hasTable('tenant_memberships');
    }

    public function currentSubscriptionForTenant(Tenant $tenant): ?TenantSubscription
    {
        if (! $this->storageReady()) {
            return null;
        }

        $subscription = $tenant->subscriptions()
            ->whereIn('status', [
                TenantSubscription::STATUS_PENDING_APPROVAL,
                TenantSubscription::STATUS_ACTIVE,
                TenantSubscription::STATUS_SUSPENDED,
                TenantSubscription::STATUS_CANCELED,
            ])
            ->orderByRaw("CASE status
                WHEN 'active' THEN 1
                WHEN 'pending_approval' THEN 2
                WHEN 'suspended' THEN 3
                WHEN 'canceled' THEN 4
                ELSE 5
            END")
            ->orderByDesc('updated_at')
            ->first();

        return $subscription instanceof TenantSubscription ? $subscription : null;
    }

    public function activeSubscriptionForTenant(Tenant $tenant): ?TenantSubscription
    {
        if (! $this->storageReady()) {
            return null;
        }

        $subscription = $tenant->subscriptions()
            ->where('status', TenantSubscription::STATUS_ACTIVE)
            ->latest('updated_at')
            ->first();

        return $subscription instanceof TenantSubscription ? $subscription : null;
    }

    public function userCanManageBilling(Tenant $tenant, ?int $userId): bool
    {
        if ($userId === null || ! $this->storageReady()) {
            return false;
        }

        return TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $userId)
            ->where('role', 'owner')
            ->exists();
    }

    public function resolveBuyer(?int $userId): ?User
    {
        if ($userId === null) {
            return null;
        }

        return User::query()->find($userId);
    }

    public function findByProviderSubscriptionId(string $provider, string $providerSubscriptionId): ?TenantSubscription
    {
        if (! $this->storageReady()) {
            return null;
        }

        return TenantSubscription::query()
            ->where('provider', $provider)
            ->where('provider_subscription_id', $providerSubscriptionId)
            ->first();
    }

    /**
     * @return Builder<TenantSubscription>
     */
    public function activeReplacementCandidates(TenantSubscription $subscription): Builder
    {
        return TenantSubscription::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('provider', $subscription->provider)
            ->where('status', TenantSubscription::STATUS_ACTIVE)
            ->where('id', '!=', $subscription->id);
    }

    public function webhookEventAlreadyProcessed(string $provider, string $eventId): ?PaymentWebhookEvent
    {
        if (! $this->storageReady()) {
            return null;
        }

        return PaymentWebhookEvent::query()
            ->where('provider', $provider)
            ->where('provider_event_id', $eventId)
            ->whereNotNull('processed_at')
            ->first();
    }
}
