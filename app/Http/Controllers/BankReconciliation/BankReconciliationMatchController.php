<?php

namespace App\Http\Controllers\BankReconciliation;

use App\Http\Controllers\Controller;
use App\Http\Requests\BankReconciliation\AutoMatchRequest;
use App\Http\Requests\BankReconciliation\ClearBroughtForwardRequest;
use App\Http\Requests\BankReconciliation\MatchStatementLineRequest;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatementLine;
use App\Services\BankReconciliation\ReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BankReconciliationMatchController extends Controller
{
    public function store(MatchStatementLineRequest $request, BankReconciliation $bankReconciliation, ReconciliationService $service): RedirectResponse
    {
        $service->match($bankReconciliation, $request->integer('statement_line_id'), $request->integer('journal_line_id'), $request->user());

        return $this->back($bankReconciliation, 'Statement line matched.');
    }

    public function autoMatch(AutoMatchRequest $request, BankReconciliation $bankReconciliation, ReconciliationService $service): RedirectResponse
    {
        $pairs = $request->has('pairs') ? array_values($request->validated('pairs') ?? []) : null;
        $applied = $service->autoMatch($bankReconciliation, $request->boolean('same_reference'), $pairs, $request->user());

        return $this->back($bankReconciliation, $applied.' suggested '.str('match')->plural($applied).' applied.');
    }

    public function broughtForward(ClearBroughtForwardRequest $request, BankReconciliation $bankReconciliation, ReconciliationService $service): RedirectResponse
    {
        $service->clearBroughtForward($bankReconciliation, $request->integer('journal_line_id'), $request->validated('note'), $request->user());

        return $this->back($bankReconciliation, 'Book entry cleared as brought forward.');
    }

    public function destroy(Request $request, BankReconciliation $bankReconciliation, BankReconciliationMatch $bankReconciliationMatch, ReconciliationService $service): RedirectResponse
    {
        $service->unmatch($bankReconciliation, $bankReconciliationMatch, $request->user());

        return $this->back($bankReconciliation, 'Match removed.');
    }

    public function ignore(Request $request, BankReconciliation $bankReconciliation, BankStatementLine $bankStatementLine, ReconciliationService $service): RedirectResponse
    {
        $service->ignore($bankReconciliation, $bankStatementLine, $request->user());

        return $this->back($bankReconciliation, 'Statement line ignored.');
    }

    public function restore(Request $request, BankReconciliation $bankReconciliation, BankStatementLine $bankStatementLine, ReconciliationService $service): RedirectResponse
    {
        $service->restore($bankReconciliation, $bankStatementLine, $request->user());

        return $this->back($bankReconciliation, 'Statement line restored.');
    }

    private function back(BankReconciliation $reconciliation, string $message): RedirectResponse
    {
        return redirect()->route('bank-reconciliations.show', $reconciliation)->with('status', $message);
    }
}
