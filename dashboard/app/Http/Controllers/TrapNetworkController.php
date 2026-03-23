<?php

namespace App\Http\Controllers;

use App\Models\TrapLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrapNetworkController extends Controller
{
    public function index(Request $request): View
    {
        $query = TrapLead::query()->latest('id');

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        $leads = $query->paginate(50)->withQueryString();

        return view('trap-network.index', [
            'leads' => $leads,
            'search' => $search,
        ]);
    }

    public function destroy(TrapLead $lead): RedirectResponse
    {
        $lead->delete();

        return back()->with('status', 'Record deleted successfully.');
    }
}
