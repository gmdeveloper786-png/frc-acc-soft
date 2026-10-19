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
use App\Models\Fund;
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

    public function show(Request $request, JournalEntry $journal, BranchAccess $access, JournalReversalService $reversals, JournalWorkflowService $workflow, JournalPostingService $posting): View
    {
        abort_unless($access->canAccess($request->user(), $journal->branch), 403);
        $journal->load(['lines.account', 'lines.service', 'lines.fund', 'voucherType', 'fiscalYear', 'accountingPeriod', 'branch', 'creator', 'submitter', 'approver', 'poster', 'reversal', 'reversalOf']);
        $openPeriods = AccountingPeriod::query()->with('fiscalYear')->where('status', 'open')->orderBy('start_date')->get();
        $reversal = $reversals->screenState($journal, $request->user());
        $canApproveJournal = $workflow->canApprove($journal, $request->user());
        $canPostJournal = $posting->canPost($journal, $request->user());

        return view('accounting.journals.show', compact('journal', 'openPeriods', 'reversal', 'canApproveJournal', 'canPostJournal'));
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

    /** Request a reversal. Nothing is posted until a separate approver and poster act. */
    public function reverse(ReverseJournalRequest $request, JournalEntry $journal, JournalReversalService $reversal): RedirectResponse
    {
        $period = AccountingPeriod::query()->findOrFail($request->integer('accounting_period_id'));
        $created = $reversal->request($journal, $period, $request->string('transaction_date')->toString(), $request->string('reason')->toString(), $request->user());

        return redirect()->route('journals.show', $created)->with('status', 'Reversal requested. It needs approval by another user and posting by a third user; the original journal stays posted until then.');
    }

    public function approveReversal(Request $request, JournalEntry $journal, JournalReversalService $reversal): RedirectResponse
    {
        $reversal->approve($journal, $request->user());

        return back()->with('status', 'Reversal approved. Another user must now post it.');
    }

    public function postReversal(Request $request, JournalEntry $journal, JournalReversalService $reversal): RedirectResponse
    {
        $posted = $reversal->post($journal, $request->user());

        return redirect()->route('journals.show', $posted)->with('status', 'Reversing journal posted; the original journal is now reversed.');
    }

    public function returnReversal(Request $request, JournalEntry $journal, JournalReversalService $reversal): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:'.JournalReversalService::MIN_REASON_LENGTH, 'max:2000']]);
        $originalId = $journal->reversal_of_id;
        $reversal->returnRequest($journal, $validated['reason'], $request->user());

        return redirect()->route('journals.show', $originalId)->with('status', 'Reversal request returned. The original journal stays posted.');
    }

    public function cancelReversal(Request $request, JournalEntry $journal, JournalReversalService $reversal): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:'.JournalReversalService::MIN_REASON_LENGTH, 'max:2000']]);
        $originalId = $journal->reversal_of_id;
        $reversal->cancel($journal, $validated['reason'], $request->user());

        return redirect()->route('journals.show', $originalId)->with('status', 'Reversal request cancelled. The original journal stays posted.');
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
            'funds' => Fund::query()->active()->orderBy('code')->get(),
        ];
    }
}
