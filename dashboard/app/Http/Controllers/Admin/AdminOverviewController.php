<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantPlanGrant;
use App\Models\TenantSubscription;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AdminOverviewController extends Controller
{
    public function index(): View
    {
        return view('admin.overview', [
            'stats' => [
                'tenants' => Tenant::query()->count(),
                'domains' => TenantDomain::query()->count(),
                'active_grants' => Schema::hasTable('tenant_plan_grants')
                    ? TenantPlanGrant::query()->where('status', TenantPlanGrant::STATUS_ACTIVE)->count()
                    : null,
                'active_subscriptions' => Schema::hasTable('tenant_subscriptions')
                    ? TenantSubscription::query()->where('status', TenantSubscription::STATUS_ACTIVE)->count()
                    : null,
            ],
        ]);
    }
}
