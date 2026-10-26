<?php

namespace App\Services\Payments;

use App\Enums\EmploymentStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PaymentBehaviour;
use App\Enums\StaffAdvanceStatus;
use App\Models\Account;
use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Models\OperationalTransaction;
use App\Models\PayableObligation;
use App\Models\Payee;
use App\Models\PayeeType;
use App\Models\PaymentPurpose;
use App\Models\PayrollRun;
use App\Models\PurchaseBill;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccountingDecimal;
use App\Services\OperationalTransactionService;
use App\Services\Payroll\PayrollRunService;
use App\Services\PettyCash\ImprestLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unified payment entry (bank source). It decides nothing about postings
 * itself: the purpose's protected behaviour hands the payment to the existing
 * workflow that owns it (vendor payment, payable settlement, payroll payment,
 * staff advance, bank payment), which keeps approval, disbursement, posting,
 * reversal, reservation and audit as they are. The debit side always comes
 * from the supporting record or a validated expense ledger. The payee and
 * purpose chosen are checked against that record and stored with a snapshot.
 */
class UnifiedPaymentService
{
    public const SOURCE_BANK = 'bank';

    public const SOURCE_CASH = 'cash';

    public const SOURCE_PETTY_CASH = 'petty_cash';

    public const SOURCES = [self::SOURCE_BANK, self::SOURCE_CASH, self::SOURCE_PETTY_CASH];

    public function __construct(
        private PaymentPurposeRegistry $purposes,
        private OperationalTransactionService $transactions,
        private PayrollRunService $payroll,
        private ImprestLedgerService $imprest,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): OperationalTransaction
    {
        $source = (string) ($data['payment_source'] ?? self::SOURCE_BANK);
        if (! in_array($source, self::SOURCES, true)) {
            throw ValidationException::withMessages(['payment_source' => 'Select bank, cash or petty cash.']);
        }
        $purpose = $this->purposes->find((int) ($data['payment_purpose_id'] ?? 0));
        if (! $purpose) {
            throw ValidationException::withMessages(['payment_purpose_id' => 'Select an active, supported payment purpose.']);
        }
        if (! in_array($source, $purpose->behaviour->sources(), true)) {
            throw ValidationException::withMessages(['payment_source' => 'This payment purpose cannot be paid from this source.']);
        }
        abort_unless($actor->can($purpose->behaviour->permission($source)), 403);
        $this->assertSourceAccount($source, $data);
        $data['payment_source'] = $source;
        $payee = $this->resolvePayee($purpose, $data);

        $common = [
            'transaction_date' => $data['transaction_date'], 'amount' => $data['amount'], 'financial_account_id' => (int) $data['financial_account_id'],
            'reference_number' => $data['reference_number'] ?? null, 'attachment_path' => $data['attachment_path'] ?? null,
        ];

        return DB::transaction(function () use ($purpose, $payee, $data, $common, $actor): OperationalTransaction {
            $transaction = match ($purpose->behaviour) {
                PaymentBehaviour::VendorBill => $this->vendorBill($data, $common, $payee, $actor),
                PaymentBehaviour::AccruedPayable => $this->accruedPayable($data, $common, $payee, $actor),
                PaymentBehaviour::SalaryPayable => $this->salary($data, $actor),
                PaymentBehaviour::StaffAdvance => $this->staffAdvance($data, $common, $payee, $actor),
                PaymentBehaviour::DirectExpense => $this->directExpense($purpose, $data, $common, $payee, $actor),
            };
            // Purpose and payee references, plus the names used today, so later renames do not rewrite history.
            $transaction->forceFill([
                'payment_purpose_id' => $purpose->id, 'payee_type_id' => $payee['type']?->id,
                'payee_employee_id' => $payee['employee']?->id, 'payee_id' => $payee['directory']?->id,
                'payee_snapshot' => [
                    'purpose' => ['id' => $purpose->id, 'code' => $purpose->code, 'name' => $purpose->name, 'behaviour' => $purpose->behaviour->value],
                    'payee_type' => $payee['type'] ? ['id' => $payee['type']->id, 'code' => $payee['type']->code, 'name' => $payee['type']->name, 'source_kind' => $payee['type']->source_kind] : null,
                    'payee' => $payee['name'] !== null ? ['source_kind' => $payee['type']?->source_kind, 'id' => $payee['id'], 'name' => $payee['name'], 'entity_kind' => $payee['directory']?->entity_kind] : null,
                ],
            ])->save();

            return $transaction;
        }, 3);
    }

    /**
     * The chosen payee type and payee: the type must be active and allowed for
     * the purpose, and the payee must be an active record of that type's source.
     *
     * @return array{type: ?PayeeType, vendor: ?Vendor, employee: ?Employee, directory: ?Payee, id: ?int, name: ?string}
     */
    private function resolvePayee(PaymentPurpose $purpose, array $data): array
    {
        $none = ['type' => null, 'vendor' => null, 'employee' => null, 'directory' => null, 'id' => null, 'name' => null];
        $type = filled($data['payee_type_id'] ?? null) ? PayeeType::query()->where('is_active', true)->find((int) $data['payee_type_id']) : null;
        if (filled($data['payee_type_id'] ?? null) && ! $type) {
            throw ValidationException::withMessages(['payee_type_id' => 'Select an active payee type.']);
        }
        if ($type && ! in_array($type->source_kind, $purpose->behaviour->payeeSources(), true)) {
            throw ValidationException::withMessages(['payee_type_id' => 'This payee type cannot be used for the selected payment purpose.']);
        }
        if (! $purpose->behaviour->requiresPayee()) {
            // Salary covers all employees of the run: the run is the payee.
            return [...$none, 'type' => $type];
        }
        if (! $type) {
            throw ValidationException::withMessages(['payee_type_id' => 'Select a payee type.']);
        }

        return match ($type->source_kind) {
            PayeeType::SOURCE_VENDOR => ($vendor = Vendor::query()->where('is_active', true)->find((int) ($data['payee_vendor_id'] ?? 0)))
                ? [...$none, 'type' => $type, 'vendor' => $vendor, 'id' => $vendor->id, 'name' => $vendor->name]
                : throw ValidationException::withMessages(['payee_vendor_id' => 'Select an active vendor.']),
            PayeeType::SOURCE_EMPLOYEE => ($employee = Employee::query()->where('employment_status', EmploymentStatus::Active->value)->find((int) ($data['payee_employee_id'] ?? 0)))
                ? [...$none, 'type' => $type, 'employee' => $employee, 'id' => $employee->id, 'name' => $employee->name]
                : throw ValidationException::withMessages(['payee_employee_id' => 'Select an active employee.']),
            PayeeType::SOURCE_DIRECTORY => ($payee = Payee::query()->where('is_active', true)->where('payee_type_id', $type->id)->find((int) ($data['payee_id'] ?? 0)))
                ? [...$none, 'type' => $type, 'directory' => $payee, 'id' => $payee->id, 'name' => $payee->name]
                : throw ValidationException::withMessages(['payee_id' => 'Select an active payee of this payee type.']),
            default => throw ValidationException::withMessages(['payee_type_id' => 'This payee type has an unsupported source.']),
        };
    }

    /**
     * The account must fit the source: a bank account for bank; a cash account that
     * holds no imprest float for cash; the cash account of an active imprest float
     * for petty cash. Branch access, the shared MCB rule and the float limit are
     * checked again by OperationalTransactionService at every step.
     */
    private function assertSourceAccount(string $source, array $data): void
    {
        $account = FinancialAccount::query()->find((int) ($data['financial_account_id'] ?? 0));
        $holdsFloat = $account && $account->type === 'cash' && $this->imprest->activeFloatFor($account) !== null;
        $fits = match ($source) {
            self::SOURCE_BANK => $account?->type === 'bank',
            self::SOURCE_CASH => $account?->type === 'cash' && ! $holdsFloat,
            self::SOURCE_PETTY_CASH => $holdsFloat,
        };
        if (! $fits) {
            throw ValidationException::withMessages(['financial_account_id' => match ($source) {
                self::SOURCE_BANK => 'Select a bank account.',
                self::SOURCE_CASH => 'Select a branch cash account. Petty cash floats are paid from the Petty Cash source.',
                self::SOURCE_PETTY_CASH => 'Select the cash account of an active petty cash float.',
            }]);
        }
    }

    /** Bank or cash payment for settlements; petty cash vouchers are only for direct expenses. */
    private function paymentType(array $data): string
    {
        return ($data['payment_source'] === self::SOURCE_CASH ? OperationalTransactionType::CashPayment : OperationalTransactionType::BankPayment)->value;
    }

    /** The supporting record decides the branch; a different branch in the request is refused, not silently replaced. */
    private function assertBranch(array $data, int $branchId): void
    {
        if ((int) ($data['branch_id'] ?? 0) !== $branchId) {
            throw ValidationException::withMessages(['branch_id' => 'The branch must be the branch of the selected record.']);
        }
    }

    private function narration(array $data, string $default): string
    {
        return filled($data['narration'] ?? null) ? (string) $data['narration'] : $default;
    }

    private function vendorBill(array $data, array $common, array $payee, User $actor): OperationalTransaction
    {
        $bill = PurchaseBill::query()->with('vendor')->find((int) ($data['purchase_bill_id'] ?? 0))
            ?? throw ValidationException::withMessages(['purchase_bill_id' => 'Select a posted purchase bill.']);
        $this->assertBranch($data, (int) $bill->branch_id);
        if ((int) $bill->vendor_id !== $payee['vendor']?->id) {
            throw ValidationException::withMessages(['purchase_bill_id' => 'The bill belongs to a different vendor than the selected payee.']);
        }

        // The vendor payment workflow re-checks posted status, branch, vendor and the outstanding amount under a lock.
        return $this->transactions->create([...$common, 'type' => OperationalTransactionType::VendorPayment->value, 'branch_id' => $bill->branch_id,
            'purchase_bill_id' => $bill->id, 'vendor_id' => $bill->vendor_id, 'party_name' => $bill->vendor?->name,
            'narration' => $this->narration($data, 'Payment of bill '.$bill->bill_number)], $actor);
    }

    private function accruedPayable(array $data, array $common, array $payee, User $actor): OperationalTransaction
    {
        $obligation = PayableObligation::query()->with('vendor')->find((int) ($data['payable_obligation_id'] ?? 0))
            ?? throw ValidationException::withMessages(['payable_obligation_id' => 'Select an open accrued payable.']);
        $this->assertBranch($data, (int) $obligation->branch_id);
        // A payable recorded against a vendor is paid to that vendor; one without a vendor to a directory payee.
        if ($obligation->vendor_id ? (int) $obligation->vendor_id !== $payee['vendor']?->id : $payee['directory'] === null) {
            throw ValidationException::withMessages(['payable_obligation_id' => $obligation->vendor_id
                ? 'This payable is owed to a different vendor than the selected payee.'
                : 'This payable has no vendor; select a payee from the Payee Directory.']);
        }

        // The payable settlement takes its debit (the payable ledger) and outstanding limit from the obligation,
        // and the request token makes a repeated submit return the same draft.
        return $this->transactions->create([...$common, 'type' => $this->paymentType($data), 'branch_id' => $obligation->branch_id,
            'payable_obligation_id' => $obligation->id, 'settlement_request_token' => $data['settlement_request_token'] ?? null,
            'vendor_id' => $obligation->vendor_id, 'party_name' => $payee['name'],
            'narration' => $this->narration($data, 'Settlement of accrued payable #'.$obligation->id)], $actor);
    }

    private function salary(array $data, User $actor): OperationalTransaction
    {
        $run = PayrollRun::query()->find((int) ($data['payroll_run_id'] ?? 0))
            ?? throw ValidationException::withMessages(['payroll_run_id' => 'Select a posted payroll run.']);
        $this->assertBranch($data, (int) $run->branch_id);

        // The payroll workflow owns the salary payable debit, the unpaid-net-salary cap and the run link.
        return $this->payroll->pay($run, [
            'type' => $this->paymentType($data), 'financial_account_id' => (int) $data['financial_account_id'],
            'amount' => $data['amount'], 'transaction_date' => $data['transaction_date'], 'reference_number' => $data['reference_number'] ?? null,
        ], $actor);
    }

    /**
     * A bank payment for exactly the approved principal, charged to the
     * advance's component ledger and tagged with the advance. Only one live
     * payment may exist per advance; it is linked to the advance automatically
     * when it is posted (OperationalTransactionService::post).
     */
    private function staffAdvance(array $data, array $common, array $payee, User $actor): OperationalTransaction
    {
        $advance = StaffAdvance::query()->with(['employee', 'component'])->lockForUpdate()->find((int) ($data['staff_advance_id'] ?? 0))
            ?? throw ValidationException::withMessages(['staff_advance_id' => 'Select an approved staff advance.']);
        if ($advance->status !== StaffAdvanceStatus::Approved || $advance->disbursement_transaction_id !== null || ! $advance->component?->account_id) {
            throw ValidationException::withMessages(['staff_advance_id' => 'Only an approved advance that has not been disbursed, with a configured advance ledger, can be paid.']);
        }
        $this->assertBranch($data, (int) $advance->branch_id);
        if ((int) $advance->employee_id !== $payee['employee']?->id) {
            throw ValidationException::withMessages(['staff_advance_id' => 'The advance belongs to a different employee than the selected payee.']);
        }
        if (! AccountingDecimal::of((string) $data['amount'])->isEqualTo(AccountingDecimal::of((string) $advance->principal_amount))) {
            throw ValidationException::withMessages(['amount' => 'An advance is disbursed for its full approved amount ('.AccountingDecimal::string(AccountingDecimal::of((string) $advance->principal_amount)).').']);
        }
        $source = 'staff_advance:'.$advance->id;
        $live = OperationalTransaction::query()->where('source_reference', $source)
            ->whereNotIn('status', [OperationalTransactionStatus::Rejected->value, OperationalTransactionStatus::Reversed->value])->lockForUpdate()->exists();
        if ($live) {
            throw ValidationException::withMessages(['staff_advance_id' => 'A payment for this advance already exists. Complete, reject or reverse it first.']);
        }

        return $this->transactions->create([...$common, 'type' => $this->paymentType($data), 'branch_id' => $advance->branch_id,
            'contra_account_id' => $advance->component->account_id, 'party_name' => $advance->employee?->name, 'source_reference' => $source,
            'narration' => $this->narration($data, 'Disbursement of '.$advance->type->label().' '.$advance->reference)], $actor);
    }

    private function directExpense(PaymentPurpose $purpose, array $data, array $common, array $payee, User $actor): OperationalTransaction
    {
        // A purpose may fix its ledger; otherwise the user picks one. Either way it must be approved for direct payment now.
        $ledgerId = $purpose->debit_account_id ?? (int) ($data['debit_account_id'] ?? 0);
        $ledger = Account::query()->eligibleForDirectPayment()->find($ledgerId);
        if (! $ledger || $ledger->hasInactiveAncestor()) {
            throw ValidationException::withMessages(['debit_account_id' => 'Select an active expense ledger approved for direct payment.']);
        }

        $type = match ($data['payment_source']) {
            self::SOURCE_PETTY_CASH => OperationalTransactionType::PettyCash->value,
            default => $this->paymentType($data),
        };

        return $this->transactions->create([...$common, 'type' => $type, 'branch_id' => (int) $data['branch_id'],
            'contra_account_id' => $ledger->id, 'vendor_id' => $payee['vendor']?->id, 'party_name' => $payee['name'],
            'service_id' => $data['service_id'] ?? null, 'fund_id' => $data['fund_id'] ?? null,
            'narration' => $this->narration($data, $purpose->name.' — '.$payee['name'])], $actor);
    }
}
