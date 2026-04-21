<?php

namespace App\Services\Mail;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class TenantOwnerNotificationService
{
    /**
     * @return array<int, string>
     */
    public function ownerEmailsForTenant(Tenant|int|string $tenant): array
    {
        if (! $this->storageReady()) {
            return [];
        }

        $tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : (int) $tenant;
        if ($tenantId <= 0) {
            return [];
        }

        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('role', 'owner')
            ->with('user:id,email')
            ->get()
            ->pluck('user.email')
            ->map(static fn (mixed $email): string => trim((string) $email))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function ownerEmailsForUser(User $user): array
    {
        if (! $this->storageReady()) {
            return [];
        }

        $tenantIds = $user->tenantMemberships()
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->map(static fn (mixed $tenantId): int => (int) $tenantId)
            ->filter(static fn (int $tenantId): bool => $tenantId > 0)
            ->unique()
            ->values()
            ->all();

        if ($tenantIds === []) {
            return [];
        }

        return TenantMembership::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('role', 'owner')
            ->with('user:id,email')
            ->get()
            ->pluck('user.email')
            ->map(static fn (mixed $email): string => trim((string) $email))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function storageReady(): bool
    {
        return Schema::hasTable('tenant_memberships') && Schema::hasTable('users');
    }
}
