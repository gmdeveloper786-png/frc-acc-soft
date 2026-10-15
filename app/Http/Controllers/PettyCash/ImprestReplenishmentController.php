<?php

namespace App\Http\Controllers\PettyCash;

use App\Http\Controllers\Controller;
use App\Http\Requests\PettyCash\StoreImprestReplenishmentRequest;
use App\Models\ImprestFloat;
use App\Services\PettyCash\ImprestReplenishmentService;
use Illuminate\Http\RedirectResponse;

class ImprestReplenishmentController extends Controller
{
    public function store(StoreImprestReplenishmentRequest $request, ImprestFloat $float, ImprestReplenishmentService $service): RedirectResponse
    {
        $replenishment = $service->request($float, $request->validated(), $request->user());

        return redirect()->route('imprest.show', $float)
            ->with('status', 'Replenishment journal prepared as a draft (journal #'.$replenishment->journal_entry_id.'). It must now be submitted, approved by another user and posted from the journal screen.');
    }
}
