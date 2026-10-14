<?php

namespace App\Services\FeePosting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FrcEnrollment;
use App\Models\FrcFeePosting;
use App\Models\FrcPayment;
use App\Models\FrcService;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\AccountMappingService;
use App\Services\BranchAccess;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * The single eligibility rule set used by the preview and by posting. The
 * external source never grants authority: a paid status alone does not bypass
 * the policy flag, permissions, branch access, mappings or period checks.
 */
class FeePostingEligibility
{
    /** Enrollment statuses whose fees count, matching the FRC Finance Report. */
    public const APPROVED_ENROLLMENT_STATUSES = ['approved', 'active', 'completed'];

    public function __construct(
        private FeePostingSettings $settings,
        private FeeSettlementMappingService $settlements,
        private AccountMappingService $accountMappings,
        private BranchAccess $branchAccess,
    ) {}

    /** Check a payment and, when eligible, build its exact journal. */
    public function forPayment(FrcPayment $payment, User $actor): FeePostingPlan
    {
        $basis = $this->settings->basis();
        $reasons = $this->policyReasons($basis);
        $payment->loadMissing('enrollment');
        $enrollment = $payment->enrollment;

        foreach ($this->sourceIssues($payment) as $code => $message) {
            $reasons[$code] = $message;
        }
        if (AccountingDecimal::of($payment->amount)->isLessThanOrEqualTo(0)) {
            $reasons['invalid_amount'] = 'The payment amount is not positive.';
        }
        if ($payment->posting_status === 'needs_review') {
            $reasons['needs_review'] = 'The payment changed at source after posting and is flagged for review. Reverse the earlier posting first.';
        }
        if ($payment->postings()->where('kind', 'payment')->where('status', 'posted')->exists()) {
            $reasons['already_posted'] = 'This payment is already posted.';
        }

        $reasons += $this->branchAndServiceReasons($payment->branch_id, $payment->service_id);
        $settlement = $this->settlements->resolve($payment->branch_id, $payment->payment_method);
        if ($settlement['issue'] !== null) {
            $reasons['settlement_mapping'] = $settlement['issue'];
        }

        $income = $this->resolveIncome($payment->service_id, $reasons);
        $receivable = $basis === FeePostingSettings::BASIS_ACCRUAL ? $this->resolveReceivable($reasons) : null;
        if ($basis === FeePostingSettings::BASIS_ACCRUAL && $enrollment && $this->recognisedAmount($enrollment)->isZero()) {
            $reasons['enrollment_not_recognised'] = 'Under the accrual basis the enrollment must be recognised (Dr Accounts Receivable, Cr fee income) before its payments are posted.';
        }

        $date = $payment->payment_date?->toDateString();
        $period = $date ? $this->openPeriodFor($date) : null;
        if (! $period) {
            $reasons['period_closed'] = 'No open fiscal year and accounting period contains the payment date '.($date ?? '(none)').'.';
        }
        $reasons += $this->actorReasons($actor, $payment->branch_id);

        if ($reasons !== []) {
            return new FeePostingPlan('payment', $basis, $reasons);
        }

        $amount = AccountingDecimal::string(AccountingDecimal::of($payment->amount));
        $credit = $basis === FeePostingSettings::BASIS_ACCRUAL ? $receivable : $income;
        $ledger = $settlement['account']->account;
        $receipt = $payment->receipt_number ?: 'FRC payment '.$payment->frc_payment_id;

        return new FeePostingPlan('payment', $basis, [], [
            $this->line($ledger, $payment->service_id, 'FRC fee received '.$receipt.' ('.$settlement['account']->name.')', $amount, '0.0000'),
            $this->line($credit, $payment->service_id, $basis === FeePostingSettings::BASIS_ACCRUAL ? 'Fee receivable settled '.$receipt : 'Fee income '.$receipt, '0.0000', $amount),
        ], 'FR', $date, $payment->branch_id, $this->paymentSourceKey($payment), $amount, $period,
            'FRC fee receipt '.$receipt.' ('.(FeeSettlementMappingService::METHODS[$payment->payment_method] ?? $payment->payment_method).')', $payment->receipt_number);
    }

    /** Check an enrollment for accrual recognition (or an adjustment of it). */
    public function forEnrollment(FrcEnrollment $enrollment, User $actor): FeePostingPlan
    {
        $basis = $this->settings->basis();
        $reasons = $this->policyReasons($basis);
        if ($basis !== FeePostingSettings::BASIS_ACCRUAL) {
            $reasons['basis_not_accrual'] = 'Enrollment recognition applies only under the accrual basis.';
        }
        foreach ($this->enrollmentIssues($enrollment) as $code => $message) {
            $reasons[$code] = $message;
        }
        $reasons += $this->branchAndServiceReasons($enrollment->branch_id, $enrollment->service_id);
        $income = $this->resolveIncome($enrollment->service_id, $reasons);
        $receivable = $this->resolveReceivable($reasons);

        $recognised = $this->recognisedAmount($enrollment);
        $difference = AccountingDecimal::of($enrollment->final_total)->minus($recognised);
        if ($difference->isZero()) {
            $reasons['nothing_to_recognise'] = $recognised->isZero()
                ? 'The enrollment total is zero; nothing is recognised.'
                : 'The enrollment total is already recognised in full.';
        }
        if ($recognised->isZero() && $difference->isNegative()) {
            $reasons['invalid_amount'] = 'The enrollment total is negative.';
        }

        $isAdjustment = ! $recognised->isZero();
        $date = ($isAdjustment ? $enrollment->source_updated_at : ($enrollment->source_approved_at ?? $enrollment->source_created_at))?->toDateString() ?? today()->toDateString();
        $period = $this->openPeriodFor($date);
        if (! $period) {
            $reasons['period_closed'] = "No open fiscal year and accounting period contains the recognition date {$date}.";
        }
        $reasons += $this->actorReasons($actor, $enrollment->branch_id);

        if ($reasons !== []) {
            return new FeePostingPlan('enrollment', $basis, $reasons);
        }

        $version = FrcFeePosting::query()->where('frc_enrollment_id', $enrollment->id)->where('kind', 'enrollment')->count() + 1;
        $amount = AccountingDecimal::string($difference->abs());
        $label = $isAdjustment ? 'Fee adjustment FRC enrollment '.$enrollment->frc_enrollment_id : 'Fee recognised FRC enrollment '.$enrollment->frc_enrollment_id;
        $lines = $difference->isPositive()
            ? [$this->line($receivable, $enrollment->service_id, $label, $amount, '0.0000'), $this->line($income, $enrollment->service_id, $label, '0.0000', $amount)]
            : [$this->line($income, $enrollment->service_id, $label, $amount, '0.0000'), $this->line($receivable, $enrollment->service_id, $label, '0.0000', $amount)];

        return new FeePostingPlan('enrollment', $basis, [], $lines, 'JV', $date, $enrollment->branch_id,
            'frc_enrollment:'.$enrollment->frc_enrollment_id.':v'.$version, AccountingDecimal::string($difference), $period, $label, 'FRC-ENR-'.$enrollment->frc_enrollment_id);
    }

    /**
     * Source-side problems only (status, deletion, enrollment). Used to decide
     * whether a reversed payment may be posted again.
     *
     * @return array<string, string>
     */
    public function sourceIssues(FrcPayment $payment): array
    {
        $reasons = [];
        if ($payment->source_status !== 'paid') {
            $reasons['source_not_paid'] = "The payment status at source is '{$payment->source_status}'; only paid payments are posted.";
        }
        if ($payment->source_deleted_at !== null) {
            $reasons['deleted_at_source'] = 'The payment was deleted at source.';
        }
        $enrollment = $payment->enrollment;
        if (! $enrollment) {
            $reasons['enrollment_missing'] = 'The payment\'s enrollment has not been mirrored.';
        } else {
            $reasons += $this->enrollmentIssues($enrollment);
        }

        return $reasons;
    }

    /** Signed amount currently recognised for an enrollment (posted postings only). */
    public function recognisedAmount(FrcEnrollment $enrollment): BigDecimal
    {
        return FrcFeePosting::query()->where('frc_enrollment_id', $enrollment->id)->where('kind', 'enrollment')->where('status', 'posted')->pluck('amount')
            ->reduce(fn (BigDecimal $sum, string $amount): BigDecimal => $sum->plus(AccountingDecimal::of($amount)), BigDecimal::zero()->toScale(4));
    }

    /** Idempotency key of the next payment posting; a re-post after reversal gets a suffix. */
    public function paymentSourceKey(FrcPayment $payment): string
    {
        $reversed = FrcFeePosting::query()->where('frc_payment_id', $payment->id)->where('kind', 'payment')->where('status', 'reversed')->count();

        return 'frc_payment:'.$payment->frc_payment_id.($reversed > 0 ? ':r'.$reversed : '');
    }

    public function openPeriodFor(string $date): ?AccountingPeriod
    {
        $period = AccountingPeriod::query()->with('fiscalYear')->where('status', AccountingPeriodStatus::Open->value)
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();

        return $period && $period->fiscalYear->status === FiscalYearStatus::Open ? $period : null;
    }

    /** @return array<string, string> */
    private function policyReasons(string $basis): array
    {
        $reasons = [];
        if (! $this->settings->policyApproved()) {
            $reasons['policy_not_approved'] = 'Fee posting is blocked: the fee accounting policy (fees.accounting_policy_approved) has not been approved by the Director.';
        }
        $other = FrcFeePosting::query()->where('basis', '!=', $basis)->value('basis');
        if ($other !== null) {
            $reasons['basis_conflict'] = "Existing fee postings use the {$other} basis but the current basis is {$basis}. Switching basis after postings exist is a migration decision; nothing is posted.";
        }

        return $reasons;
    }

    /** @return array<string, string> */
    private function enrollmentIssues(FrcEnrollment $enrollment): array
    {
        $reasons = [];
        if (! in_array($enrollment->source_status, self::APPROVED_ENROLLMENT_STATUSES, true)) {
            $reasons['enrollment_not_approved'] = "The enrollment status is '{$enrollment->source_status}'; only approved, active or completed enrollments are posted.";
        }
        if ($enrollment->source_deleted_at !== null) {
            $reasons['enrollment_deleted'] = 'The enrollment was deleted at source.';
        }

        return $reasons;
    }

    /** @return array<string, string> */
    private function branchAndServiceReasons(?int $branchId, ?int $serviceId): array
    {
        $reasons = [];
        if ($branchId === null) {
            $reasons['branch_unmapped'] = 'The FRC branch is not mapped to a local branch.';
        }
        if ($serviceId === null) {
            $reasons['service_unmapped'] = 'The FRC service is not mapped to a local service.';
        } else {
            $service = FrcService::query()->find($serviceId);
            if (! $service || ! in_array($service->status, ['publish', 'active'], true)) {
                $reasons['service_inactive'] = 'The local service is not active.';
            }
        }

        return $reasons;
    }

    /** @param array<string, string> $reasons */
    private function resolveIncome(?int $serviceId, array &$reasons): ?Account
    {
        if ($serviceId === null) {
            return null;
        }
        try {
            return $this->accountMappings->feeIncomeFor($serviceId);
        } catch (ValidationException $exception) {
            $reasons['fee_income_mapping'] = collect($exception->errors())->flatten()->first();

            return null;
        }
    }

    /** @param array<string, string> $reasons */
    private function resolveReceivable(array &$reasons): ?Account
    {
        try {
            return $this->accountMappings->resolve(AccountMappingKey::AccountsReceivable);
        } catch (ValidationException $exception) {
            $reasons['receivable_mapping'] = collect($exception->errors())->flatten()->first();

            return null;
        }
    }

    /** @return array<string, string> */
    private function actorReasons(User $actor, ?int $branchId): array
    {
        $reasons = [];
        if (! $actor->can('frc_integration.post') || ! $actor->can('journals.post')) {
            $reasons['permission_denied'] = 'You need both FRC posting and journal posting permissions.';
        }
        $branch = $branchId ? Branch::query()->find($branchId) : null;
        if ($branch && ! $this->branchAccess->canAccess($actor, $branch)) {
            $reasons['branch_access_denied'] = 'You do not have access to this branch.';
        }

        return $reasons;
    }

    /** @return array{account_id: int, account_code: string, account_name: string, service_id: ?int, description: string, debit: string, credit: string} */
    private function line(Account $account, ?int $serviceId, string $description, string $debit, string $credit): array
    {
        return ['account_id' => $account->id, 'account_code' => $account->code, 'account_name' => $account->name, 'service_id' => $serviceId, 'description' => $description, 'debit' => $debit, 'credit' => $credit];
    }
}
