<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function show(string $token): View
    {
        $invitation = $this->validInvitationOrFail($token);
        $existingUser = User::query()->where('email', $invitation->email)->first();

        return view('auth.invitation', [
            'invitation' => $invitation->load('tenant:id,name,login_path'),
            'existingUser' => $existingUser,
            'acceptAction' => route('invitations.accept', $token),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->validInvitationOrFail($token);
        $existingUser = User::query()->where('email', $invitation->email)->first();

        if ($existingUser instanceof User) {
            $validated = $request->validate([
                'password' => ['required', 'string'],
            ]);

            if (! Hash::check((string) $validated['password'], (string) $existingUser->password)) {
                return back()->withErrors(['password' => 'The password is incorrect.']);
            }

            $user = $existingUser;
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        } else {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:190'],
                'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
            ]);

            $user = User::query()->create([
                'name' => trim((string) $validated['name']),
                'email' => Str::lower((string) $invitation->email),
                'password' => (string) $validated['password'],
                'role' => 'user',
                'email_verified_at' => now(),
            ]);
        }

        DB::transaction(function () use ($invitation, $user): void {
            TenantMembership::query()->firstOrCreate(
                ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->getKey()],
                ['role' => $invitation->role]
            );

            $invitation->forceFill(['accepted_at' => now()])->save();
        });

        session()->put('is_authenticated', true);
        session()->put('is_admin', false);
        session()->put('user_id', $user->id);
        session()->put('user_name', $user->name);
        session()->put('user_email', $user->email);
        session()->put('user_avatar_path', $user->avatar_path);
        session()->put('user_role', 'user');
        session()->put('current_tenant_id', (string) $invitation->tenant_id);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Invitation accepted.');
    }

    private function validInvitationOrFail(string $token): TenantInvitation
    {
        $invitation = TenantInvitation::query()
            ->with('tenant:id,name,login_path')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        abort_unless($invitation instanceof TenantInvitation, 404);
        abort_unless($invitation->accepted_at === null, 404);
        abort_unless($invitation->expires_at->isFuture(), 404);
        abort_unless($invitation->tenant instanceof Tenant, 404);

        return $invitation;
    }
}
