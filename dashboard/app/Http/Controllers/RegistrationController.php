<?php

namespace App\Http\Controllers;

use App\Jobs\SendAccountActivationMailJob;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\TenantLoginPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if ((bool) session('is_authenticated')) {
            return redirect()->route('dashboard');
        }

        return view('auth.register');
    }

    public function pending(): View
    {
        return view('auth.register-pending');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'workspace_name' => ['required', 'string', 'max:190'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ]);

        [$tenant, $user] = DB::transaction(function () use ($validated): array {
            $tenant = Tenant::query()->create([
                'name' => trim((string) $validated['workspace_name']),
                'slug' => $this->uniqueTenantSlug((string) $validated['workspace_name']),
                'plan' => config('plans.default', 'starter'),
                'status' => 'active',
            ]);

            $tenant->forceFill([
                'login_path' => $this->uniqueLoginPath($tenant),
            ])->save();

            $user = User::withoutEvents(fn (): User => User::query()->create([
                'name' => trim((string) $validated['name']),
                'email' => Str::lower(trim((string) $validated['email'])),
                'password' => (string) $validated['password'],
                'role' => 'user',
            ]));

            TenantMembership::query()->create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $user->getKey(),
                'role' => 'owner',
            ]);

            return [$tenant, $user];
        });

        SendAccountActivationMailJob::dispatch((int) $user->getKey());

        return redirect()->route('register.pending')
            ->with('status', 'Your account has been created. We sent your login details and activation link to your email.');
    }

    private function uniqueTenantSlug(string $workspaceName): string
    {
        $base = Str::slug($workspaceName) ?: 'workspace';
        $slug = $base;
        $suffix = 1;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function uniqueLoginPath(Tenant $tenant): string
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
