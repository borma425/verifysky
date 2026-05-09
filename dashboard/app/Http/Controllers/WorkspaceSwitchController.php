<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceSwitchController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant): RedirectResponse
    {
        if ((bool) session('is_admin')) {
            abort(404);
        }

        $userId = session('user_id');
        abort_unless(is_numeric($userId), 403);

        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', (int) $userId)
            ->first();

        abort_unless($membership instanceof TenantMembership, 403);

        session()->put('current_tenant_id', (string) $tenant->getKey());

        return redirect()->route('dashboard')
            ->with('status', 'Workspace switched to '.$tenant->name.'.');
    }
}
