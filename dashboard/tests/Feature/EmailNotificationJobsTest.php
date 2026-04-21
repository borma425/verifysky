<?php

namespace Tests\Feature;

use App\Jobs\SendManualGrantActivatedMailJob;
use App\Jobs\SendWelcomeCustomerMailJob;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantUsage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailNotificationJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_welcome_email_job_is_dispatched_for_new_non_admin_users_only(): void
    {
        Queue::fake();

        User::query()->create([
            'name' => 'Customer User',
            'email' => 'customer@example.test',
            'password' => 'password',
            'role' => 'user',
        ]);
        User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        Queue::assertPushed(SendWelcomeCustomerMailJob::class, 1);
        Queue::assertPushed(SendWelcomeCustomerMailJob::class, fn (SendWelcomeCustomerMailJob $job): bool => $job->userId > 0);
    }

    public function test_manual_grant_notification_job_targets_tenant_owners_only(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 12:30:45', 'UTC'));

        $tenant = Tenant::query()->create([
            'name' => 'Notify Tenant',
            'slug' => 'notify-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 10,
            'bot_requests_used' => 10,
            'quota_status' => TenantUsage::STATUS_ACTIVE,
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

        $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
            'user_email' => 'admin@example.test',
        ])->post(route('admin.tenants.manual_grants.store', $tenant), [
            'plan_key' => 'pro',
            'duration_days' => 14,
            'reason' => 'Owner notification check',
        ])->assertRedirect();

        Queue::assertPushed(SendManualGrantActivatedMailJob::class, function (SendManualGrantActivatedMailJob $job) use ($tenant): bool {
            return $job->tenantId === $tenant->id
                && $job->recipientEmails === ['owner@example.test'];
        });
    }
}
