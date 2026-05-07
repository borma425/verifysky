<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\BillingPlanCatalogService;
use App\Services\Billing\Contracts\PaymentGatewayInterface;
use App\Services\Billing\EffectiveTenantPlanService;
use App\Services\Billing\TenantBillingStatusService;
use App\Services\Billing\TenantSubscriptionService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function __construct(
        private readonly TenantSubscriptionService $subscriptions,
        private readonly BillingPlanCatalogService $planCatalog,
        private readonly PaymentGatewayInterface $payments,
        private readonly TenantBillingStatusService $tenantBillingStatus,
        private readonly PlanLimitsService $planLimits,
        private readonly EffectiveTenantPlanService $effectivePlans
    ) {}

    public function index(): View
    {
        $tenant = $this->tenantFromSession();
        $userId = $this->currentUserId();
        $billingStatus = $this->tenantBillingStatus->forTenant($tenant);

        return view('billing.index', [
            'tenant' => $tenant,
            'currentPlan' => $this->planLimits->planDefinitionForTenant($tenant),
            'billingStatus' => $billingStatus,
            'subscription' => $this->subscriptions->currentSubscriptionForTenant($tenant),
            'paidPlans' => $this->planCatalog->paidPlans(),
            'planCards' => $this->planCatalog->displayPlans(),
            'canManageBilling' => $this->subscriptions->userCanManageBilling($tenant, $userId),
            'billingStorageReady' => $this->subscriptions->storageReady(),
            'activeGrant' => $this->effectivePlans->activeGrantForTenant($tenant),
        ]);
    }

    public function checkout(Request $request, string $plan): RedirectResponse
    {
        $tenant = $this->tenantFromSession();
        $user = $this->buyerFromSession();
        $this->ensureBillingManager($tenant, $user);

        if (! $this->subscriptions->storageReady()) {
            return back()->with('error', 'Billing storage is not ready yet. Run the billing migrations first.');
        }

        $result = $this->payments->createCheckoutSession(
            $tenant,
            $user,
            $plan,
            route('billing.checkout.success'),
            route('billing.index')
        );

        if (! $result->ok || $result->redirectUrl === null) {
            return back()->with('error', $result->error ?? 'Checkout could not be started.');
        }

        return redirect()->away($result->redirectUrl);
    }

    public function success(): View
    {
        $tenant = $this->tenantFromSession();

        return view('billing.success', [
            'tenant' => $tenant,
            'subscription' => $this->subscriptions->currentSubscriptionForTenant($tenant),
        ]);
    }

    public function cancelSubscription(): RedirectResponse
    {
        $tenant = $this->tenantFromSession();
        $user = $this->buyerFromSession();
        $this->ensureBillingManager($tenant, $user);

        $subscription = $this->subscriptions->activeSubscriptionForTenant($tenant);
        if (! $subscription instanceof TenantSubscription) {
            return back()->with('error', 'There is no active paid subscription to cancel.');
        }

        $result = $this->payments->cancelSubscription($subscription);

        return back()->with($result->ok ? 'status' : 'error', $result->message ?? $result->error);
    }

    private function tenantFromSession(): Tenant
    {
        if ((bool) session('is_admin')) {
            abort(404);
        }

        $tenantId = trim((string) session('current_tenant_id'));
        $tenant = $tenantId !== '' ? Tenant::query()->find($tenantId) : null;
        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        return $tenant;
    }

    private function buyerFromSession(): User
    {
        $userId = $this->currentUserId();
        $user = $userId !== null ? User::query()->find($userId) : null;
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function currentUserId(): ?int
    {
        $userId = session('user_id');

        return is_numeric($userId) ? (int) $userId : null;
    }

    private function ensureBillingManager(Tenant $tenant, User $user): void
    {
        if (! $this->subscriptions->userCanManageBilling($tenant, (int) $user->getKey())) {
            abort(403);
        }
    }
}
