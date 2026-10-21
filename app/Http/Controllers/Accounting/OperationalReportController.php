<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\OperationalTransaction;
use App\Models\PayableObligation;
use App\Models\PurchaseBill;
use App\Models\Vendor;
use App\Services\AccountingDecimal;
use App\Services\BranchAccess;
use App\Services\LedgerExports\LedgerReportTables;
use App\Services\Reports\ReportExporter;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalReportController extends Controller
{
    public function book(Request $request, BranchAccess $access, string $kind): View
    {
        abort_unless(in_array($kind, ['cash', 'bank'], true) && $request->user()->can($kind.'_books.view'), 403);
        $financialAccounts = $this->financialAccounts($request, $access, $kind);
        $lines = $this->bookLines($request, $access, $financialAccounts)->orderBy('id')->paginate(50)->withQueryString();
        $accountNames = $this->accountNames($financialAccounts);

        return view('accounting.reports.book', compact('kind', 'financialAccounts', 'lines', 'accountNames'));
    }

    public function exportBook(Request $request, BranchAccess $access, LedgerReportTables $tables, ReportExporter $exporter, string $kind, string $format): StreamedResponse|RedirectResponse
    {
        abort_unless(in_array($kind, ['cash', 'bank'], true) && $request->user()->can($kind.'_books.view') && $request->user()->can('finance_reports.export'), 403);
        $financialAccounts = $this->financialAccounts($request, $access, $kind);
        $meta = $this->periodMeta($request);
        if ($request->filled('financial_account_id')) {
            $selected = $financialAccounts->firstWhere('id', $request->integer('financial_account_id'));
            $meta['Account'] = $selected ? $selected->name.' — '.$selected->branch->name : '';
        }
        $table = $tables->book($kind, $this->bookLines($request, $access, $financialAccounts), $meta, $this->accountNames($financialAccounts));
        if ($table->rows === []) {
            return redirect()->route('reports.books', [...$request->query(), 'kind' => $kind])->with('error', 'There is no posted activity to export.');
        }

        return $exporter->download($table, $format, $kind.'-book');
    }

    public function register(Request $request, BranchAccess $access, string $kind): View
    {
        abort_unless($request->user()->can('finance_reports.view'), 403);
        $transactions = $this->registerTransactions($request, $access, $kind)->orderByDesc('transaction_date')->paginate(50)->withQueryString();

        return view('accounting.reports.register', compact('kind', 'transactions'));
    }

    public function exportRegister(Request $request, BranchAccess $access, LedgerReportTables $tables, ReportExporter $exporter, string $kind, string $format): StreamedResponse|RedirectResponse
    {
        $this->authorizeFinanceExport($request);
        $table = $tables->register($kind, $this->registerTransactions($request, $access, $kind), $this->periodMeta($request));
        if ($table->rows === []) {
            return redirect()->route('reports.registers', [...$request->query(), 'kind' => $kind])->with('error', 'There are no posted records to export.');
        }

        return $exporter->download($table, $format, $kind.'-register');
    }

    public function vendorLedger(Request $request, BranchAccess $access): View
    {
        abort_unless($request->user()->can('finance_reports.view'), 403);
        $vendor = $this->vendor($request);
        $bills = $this->vendorLedgerBills($request, $access, $vendor);

        return view('accounting.reports.vendor-ledger', ['vendor' => $vendor, 'vendors' => Vendor::query()->orderBy('name')->get(), 'bills' => $bills]);
    }

    public function exportVendorLedger(Request $request, BranchAccess $access, LedgerReportTables $tables, ReportExporter $exporter, string $format): StreamedResponse|RedirectResponse
    {
        $this->authorizeFinanceExport($request);
        $vendor = $this->vendor($request);
        $bills = $this->vendorLedgerBills($request, $access, $vendor)->load('vendor');
        if ($bills->isEmpty()) {
            return redirect()->route('reports.vendor-ledger', $request->query())->with('error', 'There are no posted bills to export.');
        }

        return $exporter->download($tables->vendorLedger($bills, ['Vendor' => $vendor?->name ?? 'All vendors', 'Branch' => 'All authorized branches']), $format, 'vendor-ledger');
    }

    public function payables(Request $request, BranchAccess $access): View
    {
        abort_unless($request->user()->can('finance_reports.view'), 403);
        $asOf = $request->date('as_of') ?? today();
        $bills = $this->outstandingBills($request, $access, $asOf);
        $obligations = $this->outstandingObligations($request, $access, $asOf);
        $aging = $this->aging($bills, $obligations, $asOf);

        return view('accounting.reports.payables', compact('bills', 'obligations', 'aging', 'asOf'));
    }

    public function exportPayables(Request $request, BranchAccess $access, LedgerReportTables $tables, ReportExporter $exporter, string $format): StreamedResponse|RedirectResponse
    {
        $this->authorizeFinanceExport($request);
        $asOf = $request->date('as_of') ?? today();
        $bills = $this->outstandingBills($request, $access, $asOf);
        $obligations = $this->outstandingObligations($request, $access, $asOf);
        if ($bills->isEmpty() && $obligations->isEmpty()) {
            return redirect()->route('reports.payables', $request->query())->with('error', 'There are no outstanding posted payables to export.');
        }

        return $exporter->download(
            $tables->payables($bills, $obligations, $this->aging($bills, $obligations, $asOf), fn (PurchaseBill $bill): string => $this->agingBucket($bill, $asOf), fn (PayableObligation $obligation): string => $this->obligationAgingBucket($obligation, $asOf), ['As of' => $asOf->format('d M Y'), 'Branch' => 'All authorized branches']),
            $format,
            'payables-aging-'.$asOf->toDateString(),
        );
    }

    private function authorizeFinanceExport(Request $request): void
    {
        abort_unless($request->user()->can('finance_reports.view') && $request->user()->can('finance_reports.export'), 403);
    }

    /** @return array<string, string> The date filters of the book and register screens. */
    private function periodMeta(Request $request): array
    {
        return [
            'From' => $request->filled('from') ? $request->date('from')->format('d M Y') : 'Beginning',
            'To' => $request->filled('to') ? $request->date('to')->format('d M Y') : 'Latest',
            'Branch' => 'All authorized branches',
        ];
    }

    /** @return Collection<int, FinancialAccount> */
    private function financialAccounts(Request $request, BranchAccess $access, string $kind): Collection
    {
        return FinancialAccount::query()->with(['account', 'branch'])->where('type', $kind)->whereIn('branch_id', $access->scope($request->user())->select('id'))->orderBy('name')->get();
    }

    /**
     * Posted cash or bank lines in the user's branches; shared by the screen and the export.
     * A ledger may back one account in each branch, so every line is matched to the account
     * of its own journal's branch, and an optional filter narrows the book to one account.
     *
     * @param  Collection<int, FinancialAccount>  $financialAccounts
     * @return Builder<JournalLine>
     */
    private function bookLines(Request $request, BranchAccess $access, Collection $financialAccounts): Builder
    {
        if ($request->filled('financial_account_id')) {
            $financialAccounts = $financialAccounts->where('id', $request->integer('financial_account_id'));
            abort_if($financialAccounts->isEmpty(), 404);
        }

        return JournalLine::query()->with(['journalEntry.branch', 'journalEntry.voucherType', 'account'])
            ->whereIn('account_id', $financialAccounts->pluck('account_id'))->whereHas('journalEntry', fn ($q) => $q->whereIn('status', ['posted', 'reversed'])->whereIn('branch_id', $access->scope($request->user())->select('id'))
            ->where(function ($pairs) use ($financialAccounts): void {
                foreach ($financialAccounts as $account) {
                    $pairs->orWhere(fn ($pair) => $pair->where('journal_entries.branch_id', $account->branch_id)->where('journal_lines.account_id', $account->account_id));
                }
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->date('to'))));
    }

    /**
     * @param  Collection<int, FinancialAccount>  $financialAccounts
     * @return array<string, string> Account name keyed by "ledger id:branch id".
     */
    private function accountNames(Collection $financialAccounts): array
    {
        return $financialAccounts->mapWithKeys(fn (FinancialAccount $account): array => [$account->account_id.':'.$account->branch_id => $account->name])->all();
    }

    /** @return Builder<OperationalTransaction> */
    private function registerTransactions(Request $request, BranchAccess $access, string $kind): Builder
    {
        $types = match ($kind) {
            'receipts' => [OperationalTransactionType::CashReceipt, OperationalTransactionType::BankReceipt],
            'payments' => [OperationalTransactionType::CashPayment, OperationalTransactionType::BankPayment, OperationalTransactionType::VendorPayment],
            'expenses' => [OperationalTransactionType::Expense, OperationalTransactionType::PettyCash],
            'donations' => [OperationalTransactionType::Donation],
            default => abort(404),
        };

        return OperationalTransaction::query()->with(['branch', 'financialAccount', 'vendor', 'donationCategory', 'journalEntry'])
            ->where('status', OperationalTransactionStatus::Posted)->whereIn('type', $types)->whereIn('branch_id', $access->scope($request->user())->select('id'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->date('to')));
    }

    private function vendor(Request $request): ?Vendor
    {
        return $request->filled('vendor_id') ? Vendor::query()->findOrFail($request->integer('vendor_id')) : null;
    }

    /** @return Collection<int, PurchaseBill> */
    private function vendorLedgerBills(Request $request, BranchAccess $access, ?Vendor $vendor): Collection
    {
        return PurchaseBill::query()->with(['branch', 'payments'])->where('status', 'posted')->whereIn('branch_id', $access->scope($request->user())->select('id'))->when($vendor, fn ($q) => $q->where('vendor_id', $vendor->id))->orderBy('invoice_date')->get();
    }

    /** @return Collection<int, PurchaseBill> */
    private function outstandingBills(Request $request, BranchAccess $access, CarbonInterface $asOf): Collection
    {
        return PurchaseBill::query()->with(['vendor', 'branch', 'payments'])->where('status', 'posted')->whereDate('invoice_date', '<=', $asOf)->whereIn('branch_id', $access->scope($request->user())->select('id'))->orderBy('due_date')->get()
            ->map(function (PurchaseBill $bill) use ($asOf): PurchaseBill {
                $paid = $bill->payments->filter(fn (OperationalTransaction $payment): bool => $payment->status === OperationalTransactionStatus::Posted && $payment->transaction_date->lessThanOrEqualTo($asOf))
                    ->reduce(fn (BigDecimal $sum, OperationalTransaction $payment): BigDecimal => $sum->plus(AccountingDecimal::of($payment->amount)), BigDecimal::zero()->toScale(4));
                $bill->setAttribute('report_outstanding', AccountingDecimal::string(AccountingDecimal::of($bill->total_amount)->minus($paid)));

                return $bill;
            })->filter(fn (PurchaseBill $bill): bool => AccountingDecimal::of($bill->getAttribute('report_outstanding'))->isPositive())->values();
    }

    /** @return Collection<int, PayableObligation> */
    private function outstandingObligations(Request $request, BranchAccess $access, CarbonInterface $asOf): Collection
    {
        return PayableObligation::query()->with(['vendor', 'branch', 'sourceExpense'])
            ->whereDate('obligation_date', '<=', $asOf)
            ->whereIn('branch_id', $access->scope($request->user())->select('id'))
            ->whereHas('sourceExpense', fn (Builder $query) => $query->where('status', OperationalTransactionStatus::Posted->value))
            ->orderBy('obligation_date')->get()
            ->map(function (PayableObligation $obligation) use ($asOf): PayableObligation {
                $obligation->setAttribute('report_outstanding', $obligation->outstandingAmount($asOf));

                return $obligation;
            })->filter(fn (PayableObligation $obligation): bool => AccountingDecimal::of($obligation->getAttribute('report_outstanding'))->isPositive())->values();
    }

    /**
     * @param  Collection<int, PurchaseBill>  $bills
     * @param  Collection<int, PayableObligation>  $obligations
     * @return Collection<string, BigDecimal>
     */
    private function aging(Collection $bills, Collection $obligations, CarbonInterface $asOf): Collection
    {
        $billAging = $bills->groupBy(fn (PurchaseBill $bill): string => $this->agingBucket($bill, $asOf))
            ->map(fn (Collection $items) => $items->reduce(fn (BigDecimal $sum, PurchaseBill $bill) => $sum->plus(AccountingDecimal::of($bill->getAttribute('report_outstanding'))), BigDecimal::zero()->toScale(4)));
        $obligationAging = $obligations->groupBy(fn (PayableObligation $obligation): string => $this->obligationAgingBucket($obligation, $asOf))
            ->map(fn (Collection $items) => $items->reduce(fn (BigDecimal $sum, PayableObligation $obligation) => $sum->plus(AccountingDecimal::of($obligation->getAttribute('report_outstanding'))), BigDecimal::zero()->toScale(4)));

        return collect(['Current', '1-30', '31-60', '61-90', '90+'])->mapWithKeys(fn (string $bucket): array => [$bucket => AccountingDecimal::of($billAging->get($bucket) ?? '0')->plus(AccountingDecimal::of($obligationAging->get($bucket) ?? '0'))]);
    }

    private function agingBucket(PurchaseBill $bill, CarbonInterface $asOf): string
    {
        $days = $bill->due_date ? $bill->due_date->diffInDays($asOf, false) : 0;

        return match (true) {
            $days <= 0 => 'Current', $days <= 30 => '1-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '90+'
        };
    }

    private function obligationAgingBucket(PayableObligation $obligation, CarbonInterface $asOf): string
    {
        $days = $obligation->obligation_date->diffInDays($asOf, false);

        return match (true) {
            $days <= 0 => 'Current', $days <= 30 => '1-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '90+',
        };
    }
}
