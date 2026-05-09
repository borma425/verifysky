<?php

namespace Tests\Feature;

use App\Mail\TeamInvitationMail;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_existing_and_new_users_without_storing_raw_token(): void
    {
        Mail::fake();
        [$tenant, $owner] = $this->tenantUser('team', 'owner');
        User::query()->create([
            'name' => 'Existing User',
            'email' => 'existing@example.test',
            'password' => Hash::make('User123!'),
            'role' => 'user',
            'email_verified_at' => null,
        ]);

        $this->withSession($this->sessionFor($tenant, $owner))
            ->post(route('settings.team.invitations.store'), [
                'email' => 'existing@example.test',
                'role' => 'member',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Team invitation sent.');

        $invitation = TenantInvitation::query()->where('email', 'existing@example.test')->sole();
        $this->assertSame('member', $invitation->role);
        $this->assertNull($invitation->accepted_at);
        $this->assertSame(64, strlen((string) $invitation->token_hash));

        $rawToken = null;
        Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail) use (&$rawToken): bool {
            $rawToken = Str::after((string) parse_url($mail->acceptUrl, PHP_URL_PATH), '/invitations/');

            return $mail->hasTo('existing@example.test');
        });

        $this->assertNotNull($rawToken);
        $this->assertNotSame($rawToken, $invitation->token_hash);
        $this->assertSame(hash('sha256', (string) $rawToken), $invitation->token_hash);

        $this->withSession($this->sessionFor($tenant, $owner))
            ->post(route('settings.team.invitations.store'), [
                'email' => 'new-person@example.test',
                'role' => 'owner',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Team invitation sent.');

        $this->assertDatabaseHas('tenant_invitations', [
            'tenant_id' => $tenant->getKey(),
            'email' => 'new-person@example.test',
            'role' => 'owner',
            'accepted_at' => null,
        ]);
    }

    public function test_pending_invitation_limit_rejects_twenty_first_invite(): void
    {
        Mail::fake();
        [$tenant, $owner] = $this->tenantUser('team', 'owner');

        foreach (range(1, 20) as $index) {
            TenantInvitation::query()->create([
                'tenant_id' => $tenant->getKey(),
                'email' => "pending{$index}@example.test",
                'role' => 'member',
                'token_hash' => hash('sha256', 'token-'.$index),
                'invited_by_user_id' => $owner->getKey(),
                'expires_at' => now()->addDay(),
            ]);
        }

        $this->withSession($this->sessionFor($tenant, $owner))
            ->post(route('settings.team.invitations.store'), [
                'email' => 'overflow@example.test',
                'role' => 'member',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'This workspace has reached the pending invitation limit.');

        $this->assertSame(20, TenantInvitation::query()->where('tenant_id', $tenant->getKey())->count());
        Mail::assertNothingSent();
    }

    public function test_existing_user_accepts_invite_and_gains_membership(): void
    {
        [$tenant, $owner] = $this->tenantUser('team', 'owner');
        $user = User::query()->create([
            'name' => 'Existing User',
            'email' => 'existing@example.test',
            'password' => Hash::make('User123!'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
        [$token, $invitation] = $this->invitation($tenant, $owner, 'existing@example.test');

        $this->post(route('invitations.accept', $token), [
            'password' => 'User123!',
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role' => 'member',
        ]);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame((string) $tenant->getKey(), session('current_tenant_id'));
    }

    public function test_new_user_accepts_invite_and_gets_account_membership(): void
    {
        [$tenant, $owner] = $this->tenantUser('team', 'owner');
        [$token, $invitation] = $this->invitation($tenant, $owner, 'new@example.test');

        $this->post(route('invitations.accept', $token), [
            'name' => 'New Teammate',
            'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123',
        ])->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'new@example.test')->sole();
        $this->assertSame('New Teammate', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role' => 'member',
        ]);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_invalid_expired_and_used_invitation_tokens_are_rejected(): void
    {
        [$tenant, $owner] = $this->tenantUser('team', 'owner');
        [$expiredToken] = $this->invitation($tenant, $owner, 'expired@example.test', expiresAt: now()->subMinute());
        [$usedToken, $usedInvitation] = $this->invitation($tenant, $owner, 'used@example.test');
        $usedInvitation->forceFill(['accepted_at' => now()])->save();

        $this->get(route('invitations.show', 'missing-token'))->assertNotFound();
        $this->get(route('invitations.show', $expiredToken))->assertNotFound();
        $this->get(route('invitations.show', $usedToken))->assertNotFound();
    }

    public function test_member_cannot_manage_team_billing_or_workspace_settings(): void
    {
        [$tenant, $owner] = $this->tenantUser('team', 'owner');
        $member = $this->addMember($tenant, 'Member User', 'member@example.test');
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-ACTIVE',
            'plan_key' => 'growth',
            'provider_plan_id' => 'P-GROWTH',
            'status' => TenantSubscription::STATUS_ACTIVE,
        ]);

        $this->withSession($this->sessionFor($tenant, $member))
            ->post(route('settings.team.invitations.store'), [
                'email' => 'blocked@example.test',
                'role' => 'member',
            ])
            ->assertForbidden();

        $this->withSession($this->sessionFor($tenant, $member))
            ->post(route('billing.checkout', 'growth'))
            ->assertForbidden();

        $this->withSession($this->sessionFor($tenant, $member))
            ->post(route('billing.subscription.cancel'))
            ->assertForbidden();

        $this->withSession($this->sessionFor($tenant, $member))
            ->post(route('settings.update'), [
                'email' => 'member-renamed@example.test',
                'current_password' => 'User123!',
                'name' => 'Malicious Workspace Rename',
                'login_slug' => 'malicious',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Account settings saved.');

        $tenant->refresh();
        $member->refresh();
        $this->assertSame('Team Tenant', $tenant->name);
        $this->assertSame('account/team', $tenant->login_path);
        $this->assertSame('member-renamed@example.test', $member->email);
        $this->assertDatabaseCount('tenant_invitations', 0);
    }

    public function test_member_settings_hide_workspace_and_team_controls(): void
    {
        [$tenant] = $this->tenantUser('team', 'owner');
        $member = $this->addMember($tenant, 'Member User', 'member@example.test');

        $this->withSession($this->sessionFor($tenant, $member))
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('User Profile')
            ->assertSee('Security')
            ->assertDontSee('Account Access')
            ->assertDontSee('Invite teammate')
            ->assertDontSee('Send Invitation');
    }

    public function test_owner_cannot_remove_last_owner(): void
    {
        [$tenant, $owner] = $this->tenantUser('team', 'owner');
        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $owner->getKey())
            ->sole();

        $this->withSession($this->sessionFor($tenant, $owner))
            ->delete(route('settings.team.members.destroy', $membership))
            ->assertRedirect()
            ->assertSessionHas('error', 'You cannot remove the last owner from this workspace.');

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->getKey(),
            'user_id' => $owner->getKey(),
            'role' => 'owner',
        ]);
    }

    public function test_workspace_switch_requires_membership_and_updates_context(): void
    {
        [$firstTenant, $user] = $this->tenantUser('first', 'owner');
        $secondTenant = Tenant::query()->create([
            'name' => 'Second Tenant',
            'slug' => 'second',
            'plan' => 'starter',
            'status' => 'active',
            'login_path' => 'account/second',
        ]);
        TenantMembership::query()->create([
            'tenant_id' => $secondTenant->getKey(),
            'user_id' => $user->getKey(),
            'role' => 'member',
        ]);
        $blockedTenant = Tenant::query()->create([
            'name' => 'Blocked Tenant',
            'slug' => 'blocked',
            'plan' => 'starter',
            'status' => 'active',
            'login_path' => 'account/blocked',
        ]);

        $this->withSession($this->sessionFor($firstTenant, $user))
            ->post(route('workspaces.switch', $secondTenant))
            ->assertRedirect(route('dashboard'));
        $this->assertSame((string) $secondTenant->getKey(), session('current_tenant_id'));

        $this->withSession($this->sessionFor($firstTenant, $user))
            ->post(route('workspaces.switch', $blockedTenant))
            ->assertForbidden();
        $this->assertSame((string) $firstTenant->getKey(), session('current_tenant_id'));
    }

    private function tenantUser(string $slug, string $role): array
    {
        $tenant = Tenant::query()->create([
            'name' => ucfirst($slug).' Tenant',
            'slug' => $slug,
            'plan' => 'starter',
            'status' => 'active',
            'login_path' => 'account/'.$slug,
        ]);
        $user = User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.$slug.'@example.test',
            'password' => Hash::make('User123!'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
        TenantMembership::query()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    private function addMember(Tenant $tenant, string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('User123!'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
        TenantMembership::query()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role' => 'member',
        ]);

        return $user;
    }

    private function invitation(Tenant $tenant, User $owner, string $email, mixed $expiresAt = null): array
    {
        $token = Str::random(48);
        $invitation = TenantInvitation::query()->create([
            'tenant_id' => $tenant->getKey(),
            'email' => $email,
            'role' => 'member',
            'token_hash' => hash('sha256', $token),
            'invited_by_user_id' => $owner->getKey(),
            'expires_at' => $expiresAt ?? now()->addDay(),
        ]);

        return [$token, $invitation];
    }

    private function sessionFor(Tenant $tenant, User $user): array
    {
        return [
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->getKey(),
            'user_id' => $user->getKey(),
            'user_role' => 'user',
            'user_name' => $user->name,
            'user_email' => $user->email,
        ];
    }
}
