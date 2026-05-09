<?php

namespace App\Http\Controllers;

use App\Mail\TeamInvitationMail;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantTeamController extends Controller
{
    private const PENDING_INVITE_LIMIT = 20;

    public function invite(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenantOrFail();
        $user = $this->currentUserOrFail();
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['required', Rule::in([TenantInvitation::ROLE_MEMBER, TenantInvitation::ROLE_OWNER])],
        ]);

        $email = Str::lower(trim((string) $validated['email']));
        if ($this->pendingInviteCount($tenant) >= self::PENDING_INVITE_LIMIT) {
            return back()->with('error', 'This workspace has reached the pending invitation limit.');
        }

        $existingUser = User::query()->where('email', $email)->first();
        if ($existingUser instanceof User && $tenant->memberships()->where('user_id', $existingUser->getKey())->exists()) {
            return back()->with('error', 'This user is already a member of this workspace.');
        }

        $pendingDuplicate = $tenant->invitations()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
        if ($pendingDuplicate instanceof TenantInvitation) {
            return back()->with('error', 'This email already has a pending invitation.');
        }

        $token = Str::random(48);
        $invitation = $tenant->invitations()->create([
            'email' => $email,
            'role' => (string) $validated['role'],
            'token_hash' => $this->hashToken($token),
            'invited_by_user_id' => $user->getKey(),
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($email)->send(new TeamInvitationMail(
            tenant: $tenant,
            email: $email,
            role: $invitation->role,
            acceptUrl: route('invitations.show', $token),
            invitedBy: $user,
        ));

        return back()->with('status', 'Team invitation sent.');
    }

    public function cancelInvitation(TenantInvitation $invitation): RedirectResponse
    {
        $this->ensureInvitationInCurrentTenant($invitation);
        abort_if($invitation->accepted_at !== null, 404);

        $invitation->delete();

        return back()->with('status', 'Invitation canceled.');
    }

    public function removeMember(TenantMembership $membership): RedirectResponse
    {
        $tenant = $this->currentTenantOrFail();
        abort_unless((string) $membership->tenant_id === (string) $tenant->getKey(), 404);

        if ($membership->role === TenantInvitation::ROLE_OWNER && $this->ownerCount($tenant) <= 1) {
            return back()->with('error', 'You cannot remove the last owner from this workspace.');
        }

        $removedSelf = (string) $membership->user_id === (string) session('user_id');
        $membership->delete();

        if ($removedSelf) {
            session()->forget('current_tenant_id');

            return redirect()->route('home')->with('status', 'You left the workspace.');
        }

        return back()->with('status', 'Team member removed.');
    }

    private function pendingInviteCount(Tenant $tenant): int
    {
        return $tenant->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->count();
    }

    private function ownerCount(Tenant $tenant): int
    {
        return $tenant->memberships()
            ->where('role', TenantInvitation::ROLE_OWNER)
            ->count();
    }

    private function ensureInvitationInCurrentTenant(TenantInvitation $invitation): void
    {
        $tenant = $this->currentTenantOrFail();
        abort_unless((string) $invitation->tenant_id === (string) $tenant->getKey(), 404);
    }

    private function currentTenantOrFail(): Tenant
    {
        $tenantId = trim((string) session('current_tenant_id', ''));
        abort_if($tenantId === '', 404);

        $tenant = Tenant::query()->find($tenantId);
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function currentUserOrFail(): User
    {
        $userId = session('user_id');
        abort_unless(is_numeric($userId), 403);

        $user = User::query()->find((int) $userId);
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
