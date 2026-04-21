<?php

namespace App\Services\Admin;

use App\Models\AdminImpersonationEvent;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdminCustomerMirrorAuditService
{
    public function record(Request $request, Tenant $tenant): void
    {
        if (! Schema::hasTable('admin_impersonation_events')) {
            return;
        }

        $routeName = $request->route()?->getName();

        AdminImpersonationEvent::query()->create([
            'admin_user_id' => is_numeric(session('user_id')) ? (int) session('user_id') : null,
            'admin_email' => $this->adminEmail(),
            'tenant_id' => (int) $tenant->getKey(),
            'route_action' => $routeName ?: sprintf('%s %s', $request->method(), $request->path()),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => CarbonImmutable::now('UTC')->toDateTimeString(),
        ]);
    }

    private function adminEmail(): ?string
    {
        $email = trim((string) session('user_email', session('admin_user', '')));

        return $email !== '' ? $email : null;
    }
}
