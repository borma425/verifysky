<?php

namespace App\Http\Controllers;

use App\Services\EdgeShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActionsController extends Controller
{
    public function __construct(private readonly EdgeShieldService $edgeShield)
    {
    }

    public function index(): View
    {
        return view('actions.index');
    }

    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:deploy,build,typecheck,db_init_remote,db_init_local,optimize_clear'],
        ]);

        $action = $validated['action'];
        $result = match ($action) {
            'deploy' => $this->edgeShield->runInProject('npm run deploy', 180),
            'build' => $this->edgeShield->runInProject('npm run build', 180),
            'typecheck' => $this->edgeShield->runInProject('npm run typecheck', 120),
            'db_init_remote' => $this->edgeShield->runInProject('npm run db:init', 120),
            'db_init_local' => $this->edgeShield->runInProject('npm run db:init:local', 120),
            'optimize_clear' => $this->edgeShield->runInProject('php artisan optimize:clear', 60),
        };

        if ($action === 'deploy' && $result['ok']) {
            $sync = $this->edgeShield->syncAllActiveDomainRoutes();
            $syncOutput = "Auto Route Sync:\n";
            if (!empty($sync['synced'])) {
                $syncOutput .= implode("\n", $sync['synced'])."\n";
            } else {
                $syncOutput .= "No active domains found.\n";
            }
            if (!$sync['ok']) {
                $syncOutput .= "Errors: ".($sync['error'] ?? 'Route sync failed');
                $result['error'] = trim(($result['error'] ?? '')."\n".$syncOutput);
            } else {
                $result['output'] = trim(($result['output'] ?? '')."\n".$syncOutput);
            }
        }

        return back()->with('action_result', [
            'action' => $action,
            'ok' => $result['ok'],
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
            'error' => $result['error'],
        ]);
    }
}
