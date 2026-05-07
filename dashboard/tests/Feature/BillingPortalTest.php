<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPlanGrant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_owner_can_view_billing_portal_and_non_owner_sees_read_only_notice(): void
    {
        [$tenant, $owner, $member] = $this->makeTenantWithUsers();

        $ownerResponse = $this->withSession($this->sessionFor($tenant, $owner))
            ->get(route('billing.index'));

        $memberResponse = $this->withSession($this->sessionFor($tenant, $member))
            ->get(route('billing.index'));

        $ownerResponse->assertOk()
            ->assertSee('Subscription')
            ->assertSee('Free Plan')
            ->assertSee('$0/month')
            ->assertSee('data-plan-key="starter"', false)
            ->assertSee('Current')
            ->assertDontSee('Starter Plan')
            ->assertDontSee(route('billing.checkout', 'starter'), false)
            ->assertSee('Checkout');

        $memberResponse->assertOk()
            ->assertSee('only the account owner can start checkout or cancel the subscription')
            ->assertDontSee('Cancel At Period End');
    }

    public function test_billing_portal_shows_active_manual_grant_notice_and_keeps_checkout_available(): void
    {
        [$tenant, $owner] = $this->makeTenantWithUsers();
        TenantPlanGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_plan_key' => 'pro',
            'source' => 'manual',
            'status' => TenantPlanGrant::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'reason' => 'Beta cohort',
        ]);

        $response = $this->withSession($this->sessionFor($tenant, $owner))
            ->get(route('billing.index'));

        $response->assertOk()
            ->assertSee('Bonus allowance PRO is active')
            ->assertSee('Beta cohort')
            ->assertSee('Checkout');
    }

    public function test_billing_portal_shows_trial_banner_without_bonus_wording(): void
    {
        [$tenant, $owner] = $this->makeTenantWithUsers();
        TenantPlanGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_plan_key' => 'pro',
            'source' => 'trial',
            'status' => TenantPlanGrant::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(13),
        ]);

        $response = $this->withSession($this->sessionFor($tenant, $owner))
            ->get(route('billing.index'));

        $response->assertOk()
            ->assertSee('Pro trial active')
            ->assertSee('Trial Active')
            ->assertSee('Upgrade to keep Pro')
            ->assertDontSee('Bonus allowance PRO is active')
            ->assertDontSee('Bonus PRO is active');
    }

    public function test_admin_is_redirected_away_from_customer_billing_portal(): void
    {
        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
            'user_id' => 1,
        ])->get(route('billing.index'));

        $response->assertRedirect(route('admin.overview'));
    }

    public function test_non_owner_cannot_start_checkout_or_cancel_subscription(): void
    {
        [$tenant, $owner, $member] = $this->makeTenantWithUsers();
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-ACTIVE',
            'plan_key' => 'growth',
            'provider_plan_id' => 'P-GROWTH',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'current_period_starts_at' => '2026-04-01 00:00:00',
            'current_period_ends_at' => '2026-05-01 00:00:00',
        ]);

        $this->withSession($this->sessionFor($tenant, $member))
            ->post(route('billing.checkout', 'growth'))
            ->assertForbidden();

        $this->withSession($this->sessionFor($tenant, $member))
            ->post(route('billing.subscription.cancel'))
            ->assertForbidden();
    }

    public function test_owner_can_start_checkout_for_paid_plan_and_pending_subscription_is_saved(): void
    {
        [$tenant, $owner] = $this->makeTenantWithUsers();
        $this->configurePaypal();

        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'token-123',
                'expires_in' => 3600,
            ], 200),
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions' => Http::response([
                'id' => 'I-NEW123',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://paypal.example/approve/I-NEW123'],
                ],
            ], 201),
        ]);

        $response = $this->withSession($this->sessionFor($tenant, $owner))
            ->post(route('billing.checkout', 'growth'));

        $response->assertRedirect('https://paypal.example/approve/I-NEW123');

        $subscription = TenantSubscription::query()
            ->where('provider_subscription_id', 'I-NEW123')
            ->sole();

        $this->assertSame(TenantSubscription::STATUS_PENDING_APPROVAL, $subscription->status);
        $this->assertSame('growth', $subscription->plan_key);
        $this->assertSame((string) $owner->id, (string) ($subscription->metadata_json['buyer_user_id'] ?? ''));
    }

    public function test_starter_plan_cannot_be_checked_out(): void
    {
        [$tenant, $owner] = $this->makeTenantWithUsers();

        $response = $this->withSession($this->sessionFor($tenant, $owner))
            ->post(route('billing.checkout', 'starter'));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'This plan is not available for checkout.');
    }

    public function test_owner_can_cancel_active_subscription(): void
    {
        [$tenant, $owner] = $this->makeTenantWithUsers();
        $this->configurePaypal();

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-CANCEL1',
            'plan_key' => 'growth',
            'provider_plan_id' => 'P-GROWTH',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'current_period_starts_at' => '2026-04-01 00:00:00',
            'current_period_ends_at' => '2026-05-01 00:00:00',
        ]);

        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'token-123',
                'expires_in' => 3600,
            ], 200),
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-CANCEL1/cancel' => Http::response([], 204),
        ]);

        $response = $this->withSession($this->sessionFor($tenant, $owner))
            ->post(route('billing.subscription.cancel'));

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $subscription = TenantSubscription::query()->where('provider_subscription_id', 'I-CANCEL1')->sole();
        $this->assertSame(TenantSubscription::STATUS_CANCELED, $subscription->status);
        $this->assertTrue($subscription->cancel_at_period_end);
    }

    /**
     * @return array{0:Tenant,1:User,2:User}
     */
    private function makeTenantWithUsers(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Billing Tenant',
            'slug' => 'billing-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'password',
            'role' => 'user',
        ]);

        $member = User::query()->create([
            'name' => 'Member',
            'email' => 'member@example.test',
            'password' => 'password',
            'role' => 'user',
        ]);

        TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        return [$tenant, $owner, $member];
    }

    private function sessionFor(Tenant $tenant, User $user): array
    {
        return [
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
            'user_id' => $user->id,
            'user_role' => 'user',
            'user_name' => $user->name,
        ];
    }

    private function configurePaypal(): void
    {
        config()->set('services.paypal', [
            'mode' => 'sandbox',
            'client_id' => 'client-id',
            'secret' => 'secret',
            'webhook_id' => 'wh-123',
            'brand_name' => 'VerifySky',
            'plans' => [
                'growth' => 'P-GROWTH',
                'pro' => 'P-PRO',
                'business' => 'P-BUSINESS',
                'scale' => 'P-SCALE',
            ],
        ]);
    }
}
