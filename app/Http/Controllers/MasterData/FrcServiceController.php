<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\FrcService;
use App\Services\Frc\ServiceSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FrcServiceController extends Controller
{
    public function index(Request $request): View
    {
        $services = FrcService::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->string('search')->trim()->isNotEmpty(), fn ($query) => $query->where('name', 'like', '%'.$request->string('search')->trim().'%'))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('master-data.services.index', compact('services'));
    }

    public function sync(ServiceSyncService $sync): RedirectResponse
    {
        $result = $sync->sync();

        return back()->with($result->successful ? 'status' : 'error', $result->message);
    }
}
