<?php

namespace App\Http\Controllers;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Services\EdgeShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SensitivePathsController extends Controller
{
    private EdgeShieldService $edgeShield;

    public function __construct(EdgeShieldService $edgeShield)
    {
        $this->edgeShield = $edgeShield;
    }

    public function index(): View
    {
        $tenantId = trim((string) session('current_tenant_id', ''));
        $isAdmin = (bool) session('is_admin');
        $pathsRes = $tenantId !== '' && ! $isAdmin
            ? $this->edgeShield->listTenantSensitivePaths($tenantId)
            : $this->edgeShield->listSensitivePaths();
        $paths = $pathsRes['ok'] ? ($pathsRes['paths'] ?? []) : [];
        $loadErrors = $pathsRes['ok'] ? [] : [$pathsRes['error']];

        // Split into Hard Block vs Challenge based on Action
        $criticalPaths = [];
        $mediumPaths = [];

        foreach ($paths as $path) {
            if ($path['action'] === 'block') {
                $criticalPaths[] = $path;
            } else {
                $mediumPaths[] = $path;
            }
        }

        $domainsRes = $this->edgeShield->listDomains(session('current_tenant_id'), (bool) session('is_admin'));
        $domains = $domainsRes['ok'] ? $domainsRes['domains'] : [];

        return view('sensitive_paths', compact('criticalPaths', 'mediumPaths', 'domains', 'loadErrors'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'paths' => ['required', 'array', 'min:1', 'max:50'],
            'paths.*.domain_name' => ['required', 'string', 'max:255'],
            'paths.*.path_pattern' => ['required', 'string', 'max:500'],
            'paths.*.match_type' => ['required', 'in:exact,contains,ends_with'],
            'paths.*.action' => ['required', 'in:block,challenge'],
        ]);

        $createdCount = 0;
        $domainsToPurge = [];
        $lastError = null;

        foreach ($validated['paths'] as $path) {
            $tenantId = trim((string) session('current_tenant_id', ''));
            $isAdmin = (bool) session('is_admin');
            if (! $this->canManageDomain((string) $path['domain_name'], $tenantId, $isAdmin)) {
                throw new HttpException(403, 'You do not have access to manage protected paths for this domain.');
            }
            $create = $this->edgeShield->createSensitivePath(
                $path['domain_name'],
                $path['path_pattern'],
                $path['match_type'],
                $path['action'],
                false, // Disable individual cache purge
                $tenantId !== '' && ! $isAdmin ? $tenantId : null,
                strtolower((string) $path['domain_name']) === 'global' ? ($tenantId !== '' && ! $isAdmin ? 'tenant' : 'platform') : 'domain'
            );
            if ($create['ok']) {
                $createdCount++;
                $domainsToPurge[] = $path['domain_name'];
            } else {
                $lastError = $create['error'] ?? 'Failed to protect one or more paths.';
            }
        }

        foreach (array_unique($domainsToPurge) as $domain) {
            $this->purgeSensitivePathScope((string) $domain);
        }

        if ($createdCount > 0) {
            return redirect()->route('sensitive_paths.index')->with(
                'status',
                "$createdCount protected path(s) saved."
            );
        }

        return redirect()->route('sensitive_paths.index')->with(
            'error',
            $lastError ?? 'Failed to protect paths.'
        );
    }

    public function destroy(int $pathId): RedirectResponse
    {
        $path = $this->tenantSensitivePathOrFail($pathId);
        $delete = $this->edgeShield->deleteSensitivePath($pathId);
        $this->purgeSensitivePathScope((string) ($path['domain_name'] ?? ''));

        return back()->with(
            $delete['ok'] ? 'status' : 'error',
            $delete['ok'] ? 'Protected path removed.' : ($delete['error'] ?? 'Failed to remove protected path.')
        );
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'path_ids' => ['required', 'array'],
            'path_ids.*' => ['integer'],
        ]);
        $paths = [];
        foreach ($validated['path_ids'] as $pathId) {
            $paths[] = $this->tenantSensitivePathOrFail((int) $pathId);
        }

        $delete = $this->edgeShield->deleteBulkSensitivePaths($validated['path_ids']);
        foreach ($paths as $path) {
            $this->purgeSensitivePathScope((string) ($path['domain_name'] ?? ''));
        }

        return back()->with(
            $delete['ok'] ? 'status' : 'error',
            $delete['ok'] ? 'Selected paths unlocked successfully.' : ($delete['error'] ?? 'Failed to unlock paths.')
        );
    }

    private function canManageDomain(string $domainName, string $tenantId, bool $isAdmin): bool
    {
        $domainName = strtolower(trim($domainName));
        if ($isAdmin) {
            return true;
        }
        if ($tenantId === '') {
            return false;
        }
        if ($domainName === 'global') {
            return true;
        }

        $domains = $this->edgeShield->listDomains($tenantId, false);
        if (! ($domains['ok'] ?? false)) {
            return false;
        }

        foreach ($domains['domains'] ?? [] as $domain) {
            if (strtolower((string) ($domain['domain_name'] ?? '')) === $domainName) {
                return true;
            }
        }

        return false;
    }

    private function tenantSensitivePathOrFail(int $pathId): array
    {
        $tenantId = trim((string) session('current_tenant_id', ''));
        if ((bool) session('is_admin') || $tenantId === '') {
            return ['id' => $pathId];
        }

        $paths = $this->edgeShield->listTenantSensitivePaths($tenantId)['paths'] ?? [];
        foreach ($paths as $path) {
            if ((int) ($path['id'] ?? 0) === $pathId) {
                return is_array($path) ? $path : ['id' => $pathId];
            }
        }

        throw new HttpException(404, 'Protected path not found.');
    }

    private function purgeSensitivePathScope(string $domain): void
    {
        if ((bool) session('is_admin') || strtolower(trim($domain)) !== 'global') {
            $this->edgeShield->purgeSensitivePathsCache($domain);

            return;
        }

        $tenantId = trim((string) session('current_tenant_id', ''));
        $tenant = $tenantId !== '' ? Tenant::query()->find($tenantId) : null;
        if (! $tenant instanceof Tenant) {
            $this->edgeShield->purgeSensitivePathsCache($domain);

            return;
        }

        foreach ($tenant->domains()->pluck('hostname') as $hostname) {
            PurgeRuntimeBundleCache::dispatch((string) $hostname);
        }
    }
}
