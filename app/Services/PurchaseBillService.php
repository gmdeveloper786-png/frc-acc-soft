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

            $total = BigDecimal::zero()->toScale(4);
            foreach ($data['lines'] as $line) {
                $this->assertLedger((int) $line['account_id'], 'lines.account_id', ['expense', 'asset']);
                $lineTotal = AccountingDecimal::of($line['quantity'])->multipliedBy(AccountingDecimal::of($line['unit_amount']))->toScale(4, RoundingMode::HalfUp);
                if (! $lineTotal->isPositive()) {
                    throw ValidationException::withMessages(['lines' => 'Har bill line ki total amount zero se zyada honi chahiye.']);
                }
                $total = $total->plus($lineTotal);
            }
            $data = [...$data, ...$this->purchaseOrderLink($order, $data, $total)];

            $bill = PurchaseBill::query()->create([
                ...collect($data)->except(['lines', 'attachment'])->all(),
                'total_amount' => AccountingDecimal::string($total),
                'status' => PurchaseBillStatus::Draft,
                'created_by' => $actor->id,
            ]);
            foreach ($data['lines'] as $index => $line) {
                $lineTotal = AccountingDecimal::of($line['quantity'])->multipliedBy(AccountingDecimal::of($line['unit_amount']))->toScale(4, RoundingMode::HalfUp);
                $bill->lines()->create([...$line, 'purchase_order_line_id' => filled($line['purchase_order_line_id'] ?? null) ? (int) $line['purchase_order_line_id'] : null, 'line_number' => $index + 1, 'line_total' => AccountingDecimal::string($lineTotal)]);
            }
            $this->audit->log('purchase_bills.created', $bill, ['branch_id' => $branch->id, 'vendor_id' => $bill->vendor_id, 'total_amount' => $bill->total_amount, 'purchase_order_id' => $bill->purchase_order_id, 'po_exception_status' => $bill->po_exception_status]);

            return $bill->load('lines');
        }, 3);
    }

    public function submit(PurchaseBill $bill, User $actor): PurchaseBill
    {
        return $this->transition($bill, $actor, 'purchase_bills.submit', PurchaseBillStatus::Draft, PurchaseBillStatus::Submitted, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
    }

    public function approve(PurchaseBill $bill, User $actor): PurchaseBill
    {
        abort_unless($actor->is_active && $actor->can('purchase_bills.approve'), 403);
        if (in_array($actor->id, [$bill->created_by, $bill->submitted_by], true)) {
            throw ValidationException::withMessages(['approval' => 'Bill banane ya submit karne wala user apna bill approve nahi kar sakta.']);
        }

        $changes = ['approved_by' => $actor->id, 'approved_at' => now()];
        if ($bill->po_exception_status === 'pending') {
            // Billing beyond an approved purchase order is a financial authorization
            // decision, so it fails closed to Director authority.
            if (! $actor->can('purchase_bills.approve_po_exception') || ! $this->authority->holdsDirectorRole($actor)) {
                $this->audit->log('purchase_bills.po_exception_denied', $bill, ['purchase_order_id' => $bill->purchase_order_id]);
                abort(403, 'A bill that exceeds its purchase order needs Director approval.');
            }
            $changes = [...$changes, 'po_exception_status' => 'approved', 'po_exception_approved_by' => $actor->id, 'po_exception_approved_at' => now()];
        }
        $bill = $this->transition($bill, $actor, 'purchase_bills.approve', PurchaseBillStatus::Submitted, PurchaseBillStatus::Approved, $changes);
        if ($bill->po_exception_status === 'approved') {
            $this->audit->log('purchase_bills.po_exception_approved', $bill, ['purchase_order_id' => $bill->purchase_order_id, 'reason' => $bill->po_exception_reason]);
        }

        return $bill;
    }

    public function reject(PurchaseBill $bill, string $reason, User $actor): PurchaseBill
    {
        abort_unless($actor->is_active && $actor->can('purchase_bills.approve'), 403);
        if (in_array($actor->id, [$bill->created_by, $bill->submitted_by], true)) {
            throw ValidationException::withMessages(['approval' => 'Bill banane ya submit karne wala user apna bill reject nahi kar sakta.']);
        }

        return $this->transition($bill, $actor, 'purchase_bills.approve', PurchaseBillStatus::Submitted, PurchaseBillStatus::Rejected, ['rejection_reason' => $reason]);
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

    public function reverse(PurchaseBill $bill, AccountingPeriod $period, string $date, string $reason, User $actor): PurchaseBill
    {
        abort_unless($actor->can('purchase_bills.reverse'), 403);

        return DB::transaction(function () use ($bill, $period, $date, $reason, $actor): PurchaseBill {
            $bill = PurchaseBill::query()->with(['branch', 'journalEntry'])->lockForUpdate()->findOrFail($bill->id);
            $this->guardBranch($bill, $actor);
            // A payment that is approved, disbursed or posted already commits or moved money against this bill.
            $committed = [OperationalTransactionStatus::Approved->value, OperationalTransactionStatus::Disbursed->value, OperationalTransactionStatus::Posted->value];
            if ($bill->status !== PurchaseBillStatus::Posted || ! $bill->journalEntry || $bill->payments()->whereIn('status', $committed)->exists()) {
                throw ValidationException::withMessages(['status' => 'Paid ya unposted bill reverse nahi kiya ja sakta.']);
            }
            $this->reversal->reverse($bill->journalEntry, $period, $date, $reason, $actor);
            $bill->update(['status' => PurchaseBillStatus::Reversed]);
            $this->audit->log('purchase_bills.reversed', $bill, ['reason' => $reason]);

            return $bill->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $changes */
    private function transition(PurchaseBill $bill, User $actor, string $permission, PurchaseBillStatus $from, PurchaseBillStatus $to, array $changes): PurchaseBill
    {
        abort_unless($actor->can($permission), 403);

        return DB::transaction(function () use ($bill, $actor, $from, $to, $changes): PurchaseBill {
            $bill = PurchaseBill::query()->with('branch')->lockForUpdate()->findOrFail($bill->id);
            $this->guardBranch($bill, $actor);
            if ($bill->status !== $from) {
                throw ValidationException::withMessages(['status' => 'Bill is workflow step ke liye eligible nahi.']);
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
    private function purchaseOrderLink(?PurchaseOrder $order, array $data, BigDecimal $total): array
    {
        if (! $order) {
            if (collect($data['lines'])->contains(fn (array $line): bool => filled($line['purchase_order_line_id'] ?? null))) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Bill lines can only reference a purchase order line when the bill is linked to that order.']);
            }

            return ['purchase_order_id' => null, 'po_exception_status' => null, 'po_exception_reason' => null];
        }

        // Locking reads: plain reads could miss bills committed while this transaction waited for the order.
        $activeBills = $order->activeBills()->lockForUpdate()->get(['id', 'total_amount']);
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

    private function guardBranch(PurchaseBill $bill, User $actor): void
    {
        abort_unless($this->branchAccess->canAccess($actor, $bill->branch), 403);
    }
}
