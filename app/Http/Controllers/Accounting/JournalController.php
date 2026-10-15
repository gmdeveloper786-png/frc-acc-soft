<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\JournalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReverseJournalRequest;
use App\Http\Requests\SaveJournalRequest;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\FrcService;
use App\Models\JournalEntry;
use App\Models\VoucherType;
use App\Services\BranchAccess;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\JournalWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class JournalController extends Controller
{
    public function index(Request $request, BranchAccess $access): View
    {
        $branchIds = $access->scope($request->user())->pluck('id');
        $journals = JournalEntry::query()->with(['voucherType', 'branch', 'creator'])
            ->whereIn('branch_id', $branchIds)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($query) => $query->where(function ($query) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $query->where('voucher_number', 'like', $term)->orWhere('reference_number', 'like', $term)->orWhere('narration', 'like', $term);
            }))
            ->orderByDesc('transaction_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('accounting.journals.index', compact('journals'));
    }

    public function create(Request $request, BranchAccess $access): View
    {
        return view('accounting.journals.create', $this->formData($request, $access));
    }

    public function store(SaveJournalRequest $request, JournalWorkflowService $workflow): RedirectResponse
    {
        $journal = $workflow->create($request->validated(), $request->user());

        return redirect()->route('journals.show', $journal)->with('status', 'Journal draft created.');
    }

    public function show(Request $request, JournalEntry $journal, BranchAccess $access): View
    {
        abort_unless($access->canAccess($request->user(), $journal->branch), 403);
        $journal->load(['lines.account', 'lines.service', 'voucherType', 'fiscalYear', 'accountingPeriod', 'branch', 'creator', 'submitter', 'approver', 'poster', 'reversal', 'reversalOf']);
        $openPeriods = AccountingPeriod::query()->with('fiscalYear')->where('status', 'open')->orderBy('start_date')->get();

        return view('accounting.journals.show', compact('journal', 'openPeriods'));
    }

    public function edit(Request $request, JournalEntry $journal, BranchAccess $access): View
    {
        abort_unless($access->canAccess($request->user(), $journal->branch), 403);
        abort_unless(in_array($journal->status, [JournalStatus::Draft, JournalStatus::Rejected], true), 409);
        $journal->load('lines');

        return view('accounting.journals.edit', [...$this->formData($request, $access), 'journal' => $journal]);
    }

    public function update(SaveJournalRequest $request, JournalEntry $journal, JournalWorkflowService $workflow): RedirectResponse
    {
        $workflow->update($journal, $request->validated(), $request->user());

        return redirect()->route('journals.show', $journal)->with('status', 'Journal draft updated.');
    }

    public function submit(Request $request, JournalEntry $journal, JournalWorkflowService $workflow): RedirectResponse
    {
        $workflow->submit($journal, $request->user());

        return back()->with('status', 'Journal submitted for approval.');
    }

    public function approve(Request $request, JournalEntry $journal, JournalWorkflowService $workflow): RedirectResponse
    {
        $workflow->approve($journal, $request->user());

        return back()->with('status', 'Journal approved.');
    }

    public function reject(Request $request, JournalEntry $journal, JournalWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $workflow->reject($journal, $request->user(), $validated['reason']);

        return back()->with('status', 'Journal returned for correction.');
    }

    public function destroy(Request $request, JournalEntry $journal, JournalWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $workflow->discard($journal, $request->user(), $validated['reason']);

        return redirect()->route('journals.index')->with('status', 'Draft journal discarded.');
    }

    public function post(Request $request, JournalEntry $journal, JournalPostingService $posting): RedirectResponse
    {
        $posting->post($journal, $request->user());

        return back()->with('status', 'Journal posted successfully.');
    }

    public function reverse(ReverseJournalRequest $request, JournalEntry $journal, JournalReversalService $reversal): RedirectResponse
    {
        $period = AccountingPeriod::query()->findOrFail($request->integer('accounting_period_id'));
        $created = $reversal->reverseDirect($journal, $period, $request->string('transaction_date')->toString(), $request->string('reason')->toString(), $request->user());

        return redirect()->route('journals.show', $created)->with('status', 'Reversing journal posted successfully.');
    }

    private function formData(Request $request, BranchAccess $access): array
    {
        return [
            'voucherTypes' => VoucherType::query()->where('is_active', true)->orderBy('code')->get(),
            'fiscalYears' => FiscalYear::query()->where('status', 'open')->orderByDesc('start_date')->get(),
            'periods' => AccountingPeriod::query()->where('status', 'open')->orderBy('start_date')->get(),
            'branches' => $access->scope($request->user())->orderBy('name')->get(),
            'accounts' => Account::query()->where('is_active', true)->where('is_postable', true)->where('level', 'ledger')->orderBy('code')->get(),
            'services' => FrcService::query()->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
        ];
    }
}
