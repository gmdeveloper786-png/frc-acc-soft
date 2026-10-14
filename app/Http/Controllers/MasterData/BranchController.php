<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\BranchAccess;
use App\Services\Frc\BranchSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(Request $request, BranchAccess $access): View
    {
        $branches = $access->scope($request->user())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->string('search')->trim()->isNotEmpty(), fn ($query) => $query->where('name', 'like', '%'.$request->string('search')->trim().'%'))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('master-data.branches.index', compact('branches'));
    }

    public function show(Branch $branch): View
    {
        return view('master-data.branches.show', compact('branch'));
    }

    public function sync(BranchSyncService $sync): RedirectResponse
    {
        $result = $sync->sync();

        return back()->with($result->successful ? 'status' : 'error', $result->message);
    }
}
