<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\TenantLoginPath;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantContextService
{
    public function resolveTenantIdForUser(User $user): ?string
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('tenant_memberships')) {
            return null;
        }

        $membership = $user->tenantMemberships()->orderBy('id')->first();
        if ($membership instanceof TenantMembership) {
            return (string) $membership->tenant_id;
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => $this->tenantSlugForUser($user)],
            [
                'name' => $this->tenantNameForUser($user),
                'plan' => 'starter',
                'status' => 'active',
            ]
        );

        if (Schema::hasColumn('tenants', 'login_path') && trim((string) $tenant->login_path) === '') {
            $tenant->forceFill([
                'login_path' => $this->uniqueLoginPathForTenant($tenant),
            ])->save();
        }

        TenantMembership::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['role' => 'owner']
        );

        return (string) $tenant->id;
    }

    private function tenantSlugForUser(User $user): string
    {
        return 'user-'.$user->id.'-'.Str::slug((string) $user->email);
    }

    private function tenantNameForUser(User $user): string
    {
        $name = trim((string) $user->name);

        return $name !== '' ? $name.' Tenant' : 'User '.$user->id.' Tenant';
    }

    private function uniqueLoginPathForTenant(Tenant $tenant): string
    {
        $candidate = TenantLoginPath::defaultForTenant((int) $tenant->getKey(), (string) $tenant->slug);
        $path = $candidate;
        $suffix = 1;

        while (Tenant::query()
            ->where('login_path', $path)
            ->whereKeyNot($tenant->getKey())
            ->exists()) {
            $path = $candidate.'-'.$suffix;
            $suffix++;
        }

        return $path;
    }
}
