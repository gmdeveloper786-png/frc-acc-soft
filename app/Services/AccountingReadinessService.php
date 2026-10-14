<?php

namespace App\Services;

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountLevel;
use App\Enums\AccountMappingKey;
use App\Enums\AccountType;
use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\AppSetting;
use App\Models\DonationCategory;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\FrcService;
use App\Models\VoucherType;

/**
 * Reports the real state of the posting prerequisites. It only reads data and
 * never creates records to make a check pass.
 */
class AccountingReadinessService
{
    private const REQUIRED_SETTINGS = ['currency.code', 'currency.decimals', 'fiscal.start_month', 'fiscal.initial_opening_date', 'approval.purchase_order_threshold', 'approval.payment_threshold', 'approval.purchase_order_standard_policy', 'numbering.purchase_order_prefix', 'numbering.purchase_order_padding'];

    private const REQUIRED_VOUCHER_TYPES = ['CR', 'BR', 'CP', 'BP', 'JV', 'DR', 'PB'];

    public function __construct(private AccountMappingService $mappings) {}

    /** @return list<array{key: string, label: string, ready: bool, summary: string, issues: list<string>, route: string, action: string}> */
    public function checks(): array
    {
        return [
            $this->chartImported(),
            $this->postableAccounts(),
            $this->fiscalYearOpen(),
            $this->periodOpen(),
            $this->cashBankMappings(),
            $this->feeRevenueMappings(),
            $this->donationMappings(),
            $this->payableMapping(),
            $this->financialSettings(),
        ];
    }

    public function isReady(): bool
    {
        return collect($this->checks())->every(fn (array $check): bool => $check['ready']);
    }

    private function chartImported(): array
    {
        $count = Account::query()->count();

        return $this->check('chart', 'Chart of Accounts imported', $count > 0, "{$count} approved account records exist.",
            $count > 0 ? [] : ['No Chart of Accounts record has been approved and imported yet. Review the client workbook and import only the approved rows.'],
            'account-imports.create', 'Review workbook');
    }

    private function postableAccounts(): array
    {
        $counts = Account::query()->where('level', AccountLevel::Ledger->value)->where('is_postable', true)->where('is_active', true)
            ->selectRaw('account_type, count(*) as total')->groupBy('account_type')->pluck('total', 'account_type');
        $issues = [];
        foreach (AccountType::cases() as $type) {
            if ((int) ($counts[$type->value] ?? 0) === 0) {
                $issues[] = 'No active postable '.$type->value.' ledger exists.';
            }
        }

        return $this->check('postable', 'Valid postable accounts available', $issues === [], $counts->sum().' active postable ledgers.', $issues, 'accounts.index', 'Open chart');
    }

    private function fiscalYearOpen(): array
    {
        $year = FiscalYear::query()->where('status', FiscalYearStatus::Open->value)->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->first();

        return $this->check('fiscal_year', 'Fiscal year open', $year !== null, $year ? "{$year->name} is open." : 'No open fiscal year covers today.',
            $year ? [] : ['Open the fiscal year that contains today ('.today()->toDateString().').'], 'fiscal-years.index', 'Fiscal years');
    }

    private function periodOpen(): array
    {
        $period = AccountingPeriod::query()->where('status', AccountingPeriodStatus::Open->value)->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())
            ->whereHas('fiscalYear', fn ($query) => $query->where('status', FiscalYearStatus::Open->value))->first();

        return $this->check('period', 'Accounting period open', $period !== null, $period ? "{$period->name} is open." : 'No open accounting period covers today.',
            $period ? [] : ['Open the accounting period that contains today ('.today()->toDateString().').'], 'accounting-periods.index', 'Accounting periods');
    }

    private function cashBankMappings(): array
    {
        $accounts = FinancialAccount::query()->with(['account', 'branch'])->where('is_active', true)->get();
        $issues = [];
        foreach (['cash', 'bank'] as $type) {
            if ($accounts->where('type', $type)->isEmpty()) {
                $issues[] = "No active {$type} account is configured.";
            }
        }
        foreach ($accounts as $account) {
            if (! $this->mappings->isUsable($account->account, AccountType::Asset)) {
                $issues[] = "{$account->name} ({$account->branch?->name}) is not linked to an active postable asset ledger.";
            }
        }

        return $this->check('cash_bank', 'Cash/Bank mappings valid', $issues === [], $accounts->count().' active cash and bank accounts.', $issues, 'financial-accounts.index', 'Cash & Bank');
    }

    private function feeRevenueMappings(): array
    {
        $issues = [...$this->mappingIssues(AccountMappingKey::FeeIncomeGeneral), ...$this->mappingIssues(AccountMappingKey::AccountsReceivable)];
        $services = FrcService::query()->with('revenueAccount')->whereNotNull('revenue_account_id')->get();
        foreach ($services as $service) {
            if (! $this->mappings->isUsable($service->revenueAccount, AccountType::Income)) {
                $issues[] = "Service {$service->name} is mapped to a ledger that is not an active postable income account.";
            }
        }

        return $this->check('fee_revenue', 'Fee revenue mappings valid', $issues === [], $services->count().' services have their own fee income ledger; others use General Fee Income.', $issues, 'account-mappings.index', 'GL Mapping');
    }

    private function donationMappings(): array
    {
        $categories = DonationCategory::query()->with('incomeAccount')->where('is_active', true)->get();
        $issues = $categories->isEmpty() ? ['No active donation category exists.'] : [];
        foreach ($categories as $category) {
            if (! $category->income_account_id) {
                $issues[] = "{$category->name} has no income ledger mapped.";
            } elseif (! $this->mappings->isUsable($category->incomeAccount, AccountType::Income)) {
                $issues[] = "{$category->name} is mapped to a ledger that is not an active postable income account.";
            }
        }

        return $this->check('donations', 'Donation mappings valid', $issues === [], $categories->count().' active donation categories.', $issues, 'donation-categories.index', 'Donation categories');
    }

    private function payableMapping(): array
    {
        $issues = $this->mappingIssues(AccountMappingKey::AccountsPayable);
        if (AccountMapping::query()->where('mapping_key', AccountMappingKey::PurchaseBillControl->value)->exists()) {
            $issues = [...$issues, ...$this->mappingIssues(AccountMappingKey::PurchaseBillControl)];
        }

        return $this->check('payable', 'Accounts Payable mapping valid', $issues === [], 'Used by purchase bills and accrued expenses.', $issues, 'account-mappings.index', 'GL Mapping');
    }

    private function financialSettings(): array
    {
        $present = AppSetting::query()->whereIn('key', self::REQUIRED_SETTINGS)->whereNotNull('value')->where('value', '!=', '')->pluck('key');
        $issues = collect(self::REQUIRED_SETTINGS)->diff($present)->map(fn (string $key): string => "Setting {$key} is missing.")->values()->all();
        $voucherTypes = VoucherType::query()->whereIn('code', self::REQUIRED_VOUCHER_TYPES)->where('is_active', true)->pluck('code');
        foreach (collect(self::REQUIRED_VOUCHER_TYPES)->diff($voucherTypes) as $code) {
            $issues[] = "Voucher type {$code} is missing or inactive.";
        }

        return $this->check('settings', 'Required financial settings available', $issues === [], $present->count().' of '.count(self::REQUIRED_SETTINGS).' settings and '.$voucherTypes->count().' of '.count(self::REQUIRED_VOUCHER_TYPES).' voucher types.', $issues, 'settings.edit', 'Settings');
    }

    /** @return list<string> */
    private function mappingIssues(AccountMappingKey $key): array
    {
        $mapping = AccountMapping::query()->with('account')->where('mapping_key', $key->value)->first();
        if (! $mapping) {
            return ["{$key->label()} is not mapped."];
        }

        return $this->mappings->isUsable($mapping->account, $key->accountType()) ? [] : ["{$key->label()} is mapped to a ledger that is no longer an active postable {$key->accountType()->value} account."];
    }

    /**
     * @param  list<string>  $issues
     * @return array{key: string, label: string, ready: bool, summary: string, issues: list<string>, route: string, action: string}
     */
    private function check(string $key, string $label, bool $ready, string $summary, array $issues, string $route, string $action): array
    {
        return compact('key', 'label', 'ready', 'summary', 'issues', 'route', 'action');
    }
}
