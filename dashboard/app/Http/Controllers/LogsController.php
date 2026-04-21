<?php

namespace App\Http\Controllers;

use App\Actions\Logs\AllowIpFromLogsAction;
use App\Actions\Logs\BlockIpFromLogsAction;
use App\Actions\Logs\ClearSecurityLogsAction;
use App\Http\Requests\Logs\AllowIpFromLogsRequest;
use App\Http\Requests\Logs\BlockIpFromLogsRequest;
use App\Http\Requests\Logs\ClearSecurityLogsRequest;
use App\Http\Requests\Logs\FilterSecurityLogsRequest;
use App\Repositories\SecurityLogRepository;
use App\Services\Plans\PlanLimitsService;
use App\ViewData\LogsIndexViewData;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LogsController extends Controller
{
    public function __construct(
        private readonly SecurityLogRepository $logs,
        private readonly AllowIpFromLogsAction $allowIp,
        private readonly BlockIpFromLogsAction $blockIp,
        private readonly ClearSecurityLogsAction $clearLogs,
        private readonly PlanLimitsService $planLimits
    ) {}

    public function index(FilterSecurityLogsRequest $request): View
    {
        $isAdmin = (bool) session('is_admin', false);
        $tenantId = $isAdmin ? null : (string) session('current_tenant_id', '');

        $payload = $this->logs->fetchIndexPayload($request->validated(), $tenantId, $isAdmin);
        $viewData = new LogsIndexViewData($payload, $request->query(), route('logs.index'), $isAdmin);

        return view('logs.index', $viewData->toArray());
    }

    public function allowIp(AllowIpFromLogsRequest $request): RedirectResponse
    {
        if (! $this->canActOnDomain(trim((string) $request->validated()['domain']))) {
            abort(403, 'You do not have access to this domain.');
        }
        $result = $this->allowIp->execute(
            trim((string) $request->validated()['ip']),
            trim((string) $request->validated()['domain'])
        );

        return back()->with($result['ok'] ? 'status' : 'error', $result['message'] ?? $result['error']);
    }

    public function blockIp(BlockIpFromLogsRequest $request): RedirectResponse
    {
        if (! $this->canActOnDomain(trim((string) $request->validated()['domain']))) {
            abort(403, 'You do not have access to this domain.');
        }
        $result = $this->blockIp->execute(
            trim((string) $request->validated()['ip']),
            trim((string) $request->validated()['domain'])
        );

        return back()->with($result['ok'] ? 'status' : 'error', $result['message'] ?? $result['error']);
    }

    public function clearLogs(ClearSecurityLogsRequest $request): RedirectResponse
    {
        $isAdmin = (bool) session('is_admin', false);
        $tenantId = $isAdmin ? null : (string) session('current_tenant_id', '');
        $result = $this->clearLogs->execute($request->validated()['period'], $tenantId, $isAdmin);

        return back()->with($result['ok'] ? 'status' : 'error', $result['message'] ?? $result['error']);
    }

    private function canActOnDomain(string $domain): bool
    {
        return $this->planLimits->domainBelongsToTenant(
            $domain,
            session('current_tenant_id'),
            (bool) session('is_admin')
        );
    }
}
