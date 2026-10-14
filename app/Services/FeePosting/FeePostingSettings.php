<?php

namespace App\Services\FeePosting;

use App\Models\Account;
use App\Models\AppSetting;

/**
 * Director-controlled fee accounting policy. Read straight from the database
 * on every call so a withdrawn approval takes effect immediately.
 */
class FeePostingSettings
{
    public const BASIS_CASH = 'cash';

    public const BASIS_ACCRUAL = 'accrual';

    public const DEFAULT_BASIS = self::BASIS_CASH;

    public function basis(): string
    {
        $value = AppSetting::query()->where('key', 'fees.accounting_basis')->value('value');

        return in_array($value, [self::BASIS_CASH, self::BASIS_ACCRUAL], true) ? $value : self::DEFAULT_BASIS;
    }

    public function isAccrual(): bool
    {
        return $this->basis() === self::BASIS_ACCRUAL;
    }

    public function policyApproved(): bool
    {
        return (string) AppSetting::query()->where('key', 'fees.accounting_policy_approved')->value('value') === '1';
    }

    /** The basis is still the proposed default that nobody has approved. */
    public function basisIsUnapprovedDefault(): bool
    {
        return ! $this->policyApproved() && $this->basis() === self::DEFAULT_BASIS;
    }

    public function chartOfAccountsExists(): bool
    {
        return Account::query()->exists();
    }

    /** @return array{basis: string, policy_approved: bool, unapproved_default: bool, chart_exists: bool} */
    public function summary(): array
    {
        return [
            'basis' => $this->basis(),
            'policy_approved' => $this->policyApproved(),
            'unapproved_default' => $this->basisIsUnapprovedDefault(),
            'chart_exists' => $this->chartOfAccountsExists(),
        ];
    }
}
