<?php

namespace App\Http\Controllers;

use App\Services\EdgeShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SensitivePathsController extends Controller
{
    private EdgeShieldService $edgeShield;

    public function __construct(EdgeShieldService $edgeShield)
    {
        $this->edgeShield = $edgeShield;
    }

    public function index(): View
    {
        $this->edgeShield->ensureSensitivePathsTable();

        $pathsRes = $this->edgeShield->listSensitivePaths();
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

        $domainsRes = $this->edgeShield->listDomains();
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
            $create = $this->edgeShield->createSensitivePath(
                $path['domain_name'],
                $path['path_pattern'],
                $path['match_type'],
                $path['action'],
                false // Disable individual cache purge
            );
            if ($create['ok']) {
                $createdCount++;
                $domainsToPurge[] = $path['domain_name'];
            } else {
                $lastError = $create['error'] ?? 'Failed to protect one or more paths.';
            }
        }

        foreach (array_unique($domainsToPurge) as $domain) {
            $this->edgeShield->purgeSensitivePathsCache($domain);
        }

        if ($createdCount > 0) {
            return redirect()->route('sensitive_paths.index')->with(
                'status',
                "$createdCount Sensitive paths protected successfully."
            );
        }

        return redirect()->route('sensitive_paths.index')->with(
            'error',
            $lastError ?? 'Failed to protect paths.'
        );
    }

    public function destroy(int $pathId): RedirectResponse
    {
        $delete = $this->edgeShield->deleteSensitivePath($pathId);

        return back()->with(
            $delete['ok'] ? 'status' : 'error',
            $delete['ok'] ? 'Sensitive path unlocked.' : ($delete['error'] ?? 'Failed to unlock path.')
        );
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'path_ids' => ['required', 'array'],
            'path_ids.*' => ['integer'],
        ]);

        $delete = $this->edgeShield->deleteBulkSensitivePaths($validated['path_ids']);

        return back()->with(
            $delete['ok'] ? 'status' : 'error',
            $delete['ok'] ? 'Selected paths unlocked successfully.' : ($delete['error'] ?? 'Failed to unlock paths.')
        );
    }
}
