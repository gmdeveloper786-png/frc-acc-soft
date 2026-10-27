<?php

namespace App\Services;

use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\PurchaseBillStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\PurchaseBill;
use App\Models\PurchaseBillLine;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VoucherType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseBillService
{
    public function __construct(
        private BranchAccess $branchAccess,
        private JournalPostingService $posting,
        private JournalReversalService $reversal,
        private AuditLogger $audit,
        private AccountMappingService $mappings,
        private ApprovalAuthorityService $authority,
        private PurchaseBillDebitAccountEligibility $debitAccountEligibility,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): PurchaseBill
    {
        abort_unless($actor->can('purchase_bills.create'), 403);

        return DB::transaction(function () use ($data, $actor): PurchaseBill {
            $branch = Branch::query()->findOrFail($data['branch_id']);
            abort_unless($this->branchAccess->canAccess($actor, $branch), 403);
            abort_unless(Vendor::query()->whereKey($data['vendor_id'])->where('is_active', true)->exists(), 422);
            if (blank($data['payable_account_id'] ?? null)) {
                $data['payable_account_id'] = $this->mappings->payableForBills()->id;
            }
            $this->assertLedger((int) $data['payable_account_id'], 'payable_account_id', ['liability']);
            $order = filled($data['purchase_order_id'] ?? null) ? $this->lockPurchaseOrder((int) $data['purchase_order_id'], $data) : null;

            $total = $this->lineTotal($data['lines']);
            $data = [...$data, ...$this->purchaseOrderLink($order, $data, $total)];

            $bill = PurchaseBill::query()->create([
                ...collect($data)->except(['lines', 'attachment'])->all(),
                'total_amount' => AccountingDecimal::string($total),
                'status' => PurchaseBillStatus::Draft,
                'created_by' => $actor->id,
            ]);
            $this->createLines($bill, $data['lines']);
            $this->audit->log('purchase_bills.created', $bill, ['branch_id' => $branch->id, 'vendor_id' => $bill->vendor_id, 'total_amount' => $bill->total_amount, 'purchase_order_id' => $bill->purchase_order_id, 'po_exception_status' => $bill->po_exception_status]);

            return $bill->load('lines');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(PurchaseBill $bill, array $data, User $actor): PurchaseBill
    {
        abort_unless($actor->can('purchase_bills.update'), 403);

        return DB::transaction(function () use ($bill, $data, $actor): PurchaseBill {
            $header = PurchaseBill::query()->findOrFail($bill->id);
            $this->guardBranch($header, $actor);
            if ((int) ($data['purchase_order_id'] ?? 0) !== (int) ($header->purchase_order_id ?? 0)) {
                throw ValidationException::withMessages(['purchase_order_id' => 'A linked purchase order cannot be changed while editing a bill.']);
            }

            $order = $header->purchase_order_id ? $this->lockPurchaseOrder($header->purchase_order_id, $data) : null;
            $bill = PurchaseBill::query()->with(['branch', 'lines'])->lockForUpdate()->findOrFail($bill->id);
            $this->guardBranch($bill, $actor);
            if ($bill->status !== PurchaseBillStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft purchase bill can be edited.']);
            }
            if ($bill->updated_at->format('Y-m-d H:i:s.u') !== $data['expected_updated_at']) {
                throw ValidationException::withMessages(['status' => 'This draft was changed by another user. Reload it before saving your changes.']);
            }
            if (! Vendor::query()->whereKey($data['vendor_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['vendor_id' => 'Select an active vendor.']);
            }
            if (blank($data['payable_account_id'] ?? null)) {
                $data['payable_account_id'] = $this->mappings->payableForBills()->id;
            }
            $this->assertLedger((int) $data['payable_account_id'], 'payable_account_id', ['liability']);
            $total = $this->lineTotal($data['lines']);
            $data = [...$data, ...$this->purchaseOrderLink($order, $data, $total, $bill->id)];

            $before = $this->auditSnapshot($bill);
            $bill->lines()->delete();
            $bill->update([
                ...collect($data)->except(['lines', 'attachment', 'expected_updated_at'])->all(),
                'total_amount' => AccountingDecimal::string($total),
                'rejection_reason' => null,
            ]);
            $this->createLines($bill, $data['lines']);
            $this->audit->log('purchase_bills.updated', $bill, [
                'before' => $before,
                'after' => $this->auditSnapshot($bill->fresh('lines')),
            ], $actor);

            return $bill->fresh('lines');
        }, 3);
    }

    public function submit(PurchaseBill $bill, User $actor): PurchaseBill
    {
        return $this->transition($bill, $actor, 'purchase_bills.submit', PurchaseBillStatus::Draft, PurchaseBillStatus::Submitted, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
    }

    public function approve(PurchaseBill $bill, User $actor): PurchaseBill
    {
        abort_unless($actor->is_active && $actor->can('purchase_bills.approve'), 403);
        $this->assertIndependentReviewer($bill, $actor, 'approve');

        $changes = ['approved_by' => $actor->id, 'approved_at' => now()];
        if ($bill->po_exception_status === 'pending') {
            // Billing beyond an approved purchase order is a financial authorization
            // decision, so it fails closed to Director authority.
            if (! $this->mayApprovePoException($actor)) {
                $this->audit->log('purchase_bills.po_exception_denied', $bill, ['purchase_order_id' => $bill->purchase_order_id]);
                abort(403, 'A bill that exceeds its purchase order needs Director approval.');
            }
            $changes = [...$changes, 'po_exception_status' => 'approved', 'po_exception_approved_by' => $actor->id, 'po_exception_approved_at' => now()];
        }
        $bill = $this->transition($bill, $actor, 'purchase_bills.approve', PurchaseBillStatus::Submitted, PurchaseBillStatus::Approved, $changes,
            function (PurchaseBill $locked) use ($actor, $changes): void {
                // Re-checked on the locked row: the actor is still eligible and the PO exception state is unchanged.
                abort_unless($actor->is_active && $actor->can('purchase_bills.approve'), 403);
                $this->assertIndependentReviewer($locked, $actor, 'approve');
                $pending = $locked->po_exception_status === 'pending';
                abort_if($pending !== isset($changes['po_exception_status']) || ($pending && ! $this->mayApprovePoException($actor)), 403, 'A bill that exceeds its purchase order needs Director approval.');
            });
        if ($bill->po_exception_status === 'approved') {
            $this->audit->log('purchase_bills.po_exception_approved', $bill, ['purchase_order_id' => $bill->purchase_order_id, 'reason' => $bill->po_exception_reason]);
        }

        return $bill;
    }

    public function reject(PurchaseBill $bill, string $reason, User $actor): PurchaseBill
    {
        abort_unless($actor->is_active && $actor->can('purchase_bills.approve'), 403);
        $this->assertIndependentReviewer($bill, $actor, 'reject');

        return $this->transition($bill, $actor, 'purchase_bills.approve', PurchaseBillStatus::Submitted, PurchaseBillStatus::Rejected, ['rejection_reason' => $reason],
            function (PurchaseBill $locked) use ($actor): void {
                abort_unless($actor->is_active && $actor->can('purchase_bills.approve'), 403);
                $this->assertIndependentReviewer($locked, $actor, 'reject');
            });
    }

    /** Whether the user may approve this bill now, for the screen. approve() checks everything again inside its lock. */
    public function canApprove(PurchaseBill $bill, User $user): bool
    {
        return $this->canReturn($bill, $user)
            && ($bill->po_exception_status !== 'pending' || $this->mayApprovePoException($user));
    }

    /** Whether the user may return this submitted bill for correction now, for the screen. */
    public function canReturn(PurchaseBill $bill, User $user): bool
    {
        return $bill->status === PurchaseBillStatus::Submitted
            && $user->is_active && $user->can('purchase_bills.approve')
            && $this->branchAccess->canAccess($user, $bill->branch)
            && ! in_array((int) $user->id, $this->preparerIds($bill), true);
    }

    /**
     * Everyone who prepared the bill: its creator, its submitter and every user
     * who edited the draft. None of them may approve or return it.
     *
     * @return list<int>
     */
    public function preparerIds(PurchaseBill $bill): array
    {
        return collect([$bill->created_by, $bill->submitted_by, ...$this->audit->actorIds($bill, ['purchase_bills.updated'])])
            ->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    private function assertIndependentReviewer(PurchaseBill $bill, User $actor, string $action): void
    {
        if (in_array((int) $actor->id, $this->preparerIds($bill), true)) {
            throw ValidationException::withMessages(['approval' => "Bill banane, edit karne ya submit karne wala user apna bill {$action} nahi kar sakta."]);
        }
    }

    private function mayApprovePoException(User $actor): bool
    {
        return $actor->can('purchase_bills.approve_po_exception') && $this->authority->holdsDirectorRole($actor);
    }

    public function post(PurchaseBill $bill, User $actor): PurchaseBill
    {
        abort_unless($actor->can('purchase_bills.post'), 403);

        return DB::transaction(function () use ($bill, $actor): PurchaseBill {
            $bill = PurchaseBill::query()->with(['branch', 'lines'])->lockForUpdate()->findOrFail($bill->id);
            $this->guardBranch($bill, $actor);
            if ($bill->status !== PurchaseBillStatus::Approved || $bill->journal_entry_id) {
                throw ValidationException::withMessages(['status' => 'Sirf approved aur unposted bill post ho sakta hai.']);
            }
            foreach ($bill->lines as $line) {
                $this->debitAccountEligibility->assertEligible($line->account_id, 'lines.account_id');
            }
            $period = AccountingPeriod::query()->with('fiscalYear')->where('status', 'open')->whereDate('start_date', '<=', $bill->invoice_date)->whereDate('end_date', '>=', $bill->invoice_date)->first();
            if (! $period || $period->fiscalYear->status->value !== 'open') {
                throw ValidationException::withMessages(['invoice_date' => 'Is date ke liye open accounting period mojood nahi.']);
            }
            $voucher = VoucherType::query()->where('code', 'PB')->where('is_active', true)->firstOrFail();
            $journal = JournalEntry::query()->create([
                'voucher_type_id' => $voucher->id, 'transaction_date' => $bill->invoice_date,
                'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id,
                'branch_id' => $bill->branch_id, 'narration' => $bill->description ?: 'Purchase bill '.$bill->bill_number,
                'reference_number' => $bill->bill_number, 'source_system' => 'accounting', 'source_type' => 'purchase_bill',
                'source_id' => (string) $bill->id, 'source_key' => 'purchase_bill:'.$bill->id, 'status' => JournalStatus::Approved,
                'total_debit' => $bill->total_amount, 'total_credit' => $bill->total_amount,
                'created_by' => $bill->created_by, 'submitted_by' => $bill->submitted_by, 'approved_by' => $bill->approved_by,
                'submitted_at' => $bill->submitted_at, 'approved_at' => $bill->approved_at,
            ]);
            foreach ($bill->lines as $index => $line) {
                $journal->lines()->create(['line_number' => $index + 1, 'account_id' => $line->account_id, 'service_id' => $line->service_id, 'description' => $line->description, 'debit' => $line->line_total, 'credit' => '0.0000']);
            }
            $journal->lines()->create(['line_number' => $bill->lines->count() + 1, 'account_id' => $bill->payable_account_id, 'description' => 'Accounts payable - '.$bill->bill_number, 'debit' => '0.0000', 'credit' => $bill->total_amount]);
            $journal = $this->posting->postSystem($journal, $actor);
            $bill->update(['status' => PurchaseBillStatus::Posted, 'journal_entry_id' => $journal->id, 'posted_at' => now()]);
            $this->audit->log('purchase_bills.posted', $bill, ['journal_entry_id' => $journal->id, 'voucher_number' => $journal->voucher_number]);

            return $bill->refresh();
        }, 3);
    }

    /** Whether committed payments block reversal of this posted bill. */
    public function hasCommittedPayments(PurchaseBill $bill): bool
    {
        $committed = [OperationalTransactionStatus::Approved->value, OperationalTransactionStatus::Disbursed->value, OperationalTransactionStatus::Posted->value];

        return $bill->payments()->whereIn('status', $committed)->exists();
    }

    /** Whether the user may reverse this purchase bill now, for the screen. reverse() checks everything again inside its lock. */
    public function canReverse(PurchaseBill $bill, User $user): bool
    {
        return $bill->status === PurchaseBillStatus::Posted
            && $bill->journal_entry_id !== null
            && $user->is_active
            && $user->can('purchase_bills.reverse')
            && $this->branchAccess->canAccess($user, $bill->branch)
            && ! $this->hasCommittedPayments($bill);
    }

    public function reverse(PurchaseBill $bill, AccountingPeriod $period, string $date, string $reason, User $actor): PurchaseBill
    {
        abort_unless($actor->can('purchase_bills.reverse'), 403);

        return DB::transaction(function () use ($bill, $period, $date, $reason, $actor): PurchaseBill {
            $bill = PurchaseBill::query()->with(['branch', 'journalEntry'])->lockForUpdate()->findOrFail($bill->id);
            $this->guardBranch($bill, $actor);
            // A payment that is approved, disbursed or posted already commits or moved money against this bill.
            if ($bill->status !== PurchaseBillStatus::Posted || ! $bill->journalEntry || $this->hasCommittedPayments($bill)) {
                throw ValidationException::withMessages(['status' => 'Paid ya unposted bill reverse nahi kiya ja sakta.']);
            }
            $this->reversal->reverse($bill->journalEntry, $period, $date, $reason, $actor);
            $bill->update(['status' => PurchaseBillStatus::Reversed]);
            $this->audit->log('purchase_bills.reversed', $bill, ['reason' => $reason]);

            return $bill->refresh();
        }, 3);
    }

    /** Whether the user may cancel this draft purchase bill now, for the screen. cancelDraft() checks everything again inside its lock. */
    public function canCancelDraft(PurchaseBill $bill, User $user): bool
    {
        return $bill->status === PurchaseBillStatus::Draft
            && $user->is_active
            && $user->can('purchase_bills.cancel')
            && $this->branchAccess->canAccess($user, $bill->branch);
    }

    public function cancelDraft(PurchaseBill $bill, string $reason, User $actor): PurchaseBill
    {
        abort_unless($actor->is_active && $actor->can('purchase_bills.cancel'), 403);

        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Cancellation reason must be between 5 and 2000 characters.']);
        }

        return DB::transaction(function () use ($bill, $reason, $actor): PurchaseBill {
            $bill = PurchaseBill::query()->with('branch')->lockForUpdate()->findOrFail($bill->id);
            $this->guardBranch($bill, $actor);

            if ($bill->status !== PurchaseBillStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Sirf draft purchase bill cancel kiya ja sakta hai.']);
            }

            $fromStatus = $bill->status;
            $bill->update(['status' => PurchaseBillStatus::Cancelled]);

            $this->audit->log('purchase_bills.cancelled', $bill, [
                'from_status' => $fromStatus->value,
                'reason' => $reason,
                'cancelled_by' => $actor->id,
                'bill_number' => $bill->bill_number,
                'total_amount' => $bill->total_amount,
            ], $actor);

            return $bill->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  (callable(PurchaseBill): void)|null  $guard  extra checks on the locked row, before anything changes
     */
    private function transition(PurchaseBill $bill, User $actor, string $permission, PurchaseBillStatus $from, PurchaseBillStatus $to, array $changes, ?callable $guard = null): PurchaseBill
    {
        abort_unless($actor->can($permission), 403);

        return DB::transaction(function () use ($bill, $actor, $from, $to, $changes, $guard): PurchaseBill {
            $bill = PurchaseBill::query()->with('branch')->lockForUpdate()->findOrFail($bill->id);
            $this->guardBranch($bill, $actor);
            if ($bill->status !== $from) {
                throw ValidationException::withMessages(['status' => 'Bill is workflow step ke liye eligible nahi.']);
            }
            if ($guard) {
                $guard($bill);
            }
            $bill->update([...$changes, 'status' => $to]);
            $this->audit->log('purchase_bills.'.$to->value, $bill, ['from_status' => $from->value, 'total_amount' => $bill->total_amount]);

            return $bill->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    private function lockPurchaseOrder(int $orderId, array $data): PurchaseOrder
    {
        $order = PurchaseOrder::query()->with('lines')->lockForUpdate()->find($orderId);
        if (! $order || ! $order->status->acceptsBills()) {
            throw ValidationException::withMessages(['purchase_order_id' => 'Only an approved or issued purchase order can be billed.']);
        }
        if ($order->branch_id !== (int) $data['branch_id'] || $order->vendor_id !== (int) $data['vendor_id']) {
            throw ValidationException::withMessages(['purchase_order_id' => 'The purchase order belongs to a different vendor or branch.']);
        }

        return $order;
    }

    /**
     * Reconcile a bill against its purchase order. A bill above the remaining
     * approved value or quantity is only accepted as a documented exception
     * that a separately authorized user must approve.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function purchaseOrderLink(?PurchaseOrder $order, array $data, BigDecimal $total, ?int $excludingBillId = null): array
    {
        if (! $order) {
            if (collect($data['lines'])->contains(fn (array $line): bool => filled($line['purchase_order_line_id'] ?? null))) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Bill lines can only reference a purchase order line when the bill is linked to that order.']);
            }

            return ['purchase_order_id' => null, 'po_exception_status' => null, 'po_exception_reason' => null];
        }

        // Locking reads: plain reads could miss bills committed while this transaction waited for the order.
        $activeBills = $order->activeBills()->when($excludingBillId, fn ($query) => $query->whereKeyNot($excludingBillId))->lockForUpdate()->get(['id', 'total_amount']);
        $billed = $activeBills->reduce(fn (BigDecimal $sum, PurchaseBill $bill): BigDecimal => $sum->plus(AccountingDecimal::of($bill->total_amount)), BigDecimal::zero()->toScale(4));
        $billedLines = PurchaseBillLine::query()->whereIn('purchase_bill_id', $activeBills->pluck('id'))->whereNotNull('purchase_order_line_id')->lockForUpdate()->get(['id', 'purchase_order_line_id', 'quantity']);
        $exceeds = $billed->plus($total)->isGreaterThan(AccountingDecimal::of($order->total_amount));
        $quantities = [];
        foreach ($data['lines'] as $line) {
            if (blank($line['purchase_order_line_id'] ?? null)) {
                continue;
            }
            $id = (int) $line['purchase_order_line_id'];
            if (! $order->lines->contains('id', $id)) {
                throw ValidationException::withMessages(['lines' => 'A bill line references a line of another purchase order.']);
            }
            $quantities[$id] = ($quantities[$id] ?? BigDecimal::zero()->toScale(4))->plus(AccountingDecimal::of($line['quantity']));
        }
        foreach ($quantities as $id => $quantity) {
            $orderLine = $order->lines->firstWhere('id', $id);
            $billedQuantity = $billedLines->where('purchase_order_line_id', $id)->reduce(fn (BigDecimal $sum, PurchaseBillLine $line): BigDecimal => $sum->plus(AccountingDecimal::of($line->quantity)), BigDecimal::zero()->toScale(4));
            $exceeds = $exceeds || $billedQuantity->plus($quantity)->isGreaterThan(AccountingDecimal::of($orderLine->quantity));
        }

        $reason = trim((string) ($data['po_exception_reason'] ?? ''));
        $remaining = AccountingDecimal::string(AccountingDecimal::of($order->total_amount)->minus($billed));
        if ($exceeds && mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['po_exception_reason' => "This bill exceeds the remaining approved quantity or value of {$order->po_number} (remaining {$remaining}). Reduce the bill or record a documented exception reason for separate approval."]);
        }

        return [
            'purchase_order_id' => $order->id,
            'purchase_order_reference' => $order->po_number,
            'po_exception_status' => $exceeds ? 'pending' : null,
            'po_exception_reason' => $exceeds ? $reason : null,
        ];
    }

    /** @param list<string> $types */
    private function assertLedger(int $id, string $field, array $types): void
    {
        if (! Account::query()->whereKey($id)->whereIn('account_type', $types)->where('level', 'ledger')->where('is_postable', true)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([$field => 'Active postable ledger account required hai.']);
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function lineTotal(array $lines): BigDecimal
    {
        $total = BigDecimal::zero()->toScale(4);
        foreach ($lines as $line) {
            $this->debitAccountEligibility->assertEligible((int) $line['account_id'], 'lines.account_id');
            $lineTotal = AccountingDecimal::of($line['quantity'])->multipliedBy(AccountingDecimal::of($line['unit_amount']))->toScale(4, RoundingMode::HalfUp);
            if (! $lineTotal->isPositive()) {
                throw ValidationException::withMessages(['lines' => 'Har bill line ki total amount zero se zyada honi chahiye.']);
            }
            $total = $total->plus($lineTotal);
        }

        return $total;
    }

    /** @param list<array<string, mixed>> $lines */
    private function createLines(PurchaseBill $bill, array $lines): void
    {
        foreach ($lines as $index => $line) {
            $lineTotal = AccountingDecimal::of($line['quantity'])->multipliedBy(AccountingDecimal::of($line['unit_amount']))->toScale(4, RoundingMode::HalfUp);
            $bill->lines()->create([...$line, 'purchase_order_line_id' => filled($line['purchase_order_line_id'] ?? null) ? (int) $line['purchase_order_line_id'] : null, 'line_number' => $index + 1, 'line_total' => AccountingDecimal::string($lineTotal)]);
        }
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(PurchaseBill $bill): array
    {
        return [
            'vendor_id' => $bill->vendor_id,
            'branch_id' => $bill->branch_id,
            'payable_account_id' => $bill->payable_account_id,
            'bill_number' => $bill->bill_number,
            'invoice_date' => $bill->invoice_date->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'purchase_order_id' => $bill->purchase_order_id,
            'description' => $bill->description,
            'attachment_path' => $bill->attachment_path,
            'total_amount' => $bill->total_amount,
            'lines' => $bill->lines->map(fn (PurchaseBillLine $line): array => $line->only(['account_id', 'service_id', 'description', 'quantity', 'unit_amount', 'line_total', 'purchase_order_line_id']))->values()->all(),
        ];
    }

    private function guardBranch(PurchaseBill $bill, User $actor): void
    {
        abort_unless($this->branchAccess->canAccess($actor, $bill->branch), 403);
    }
}
