<?php

use App\Http\Controllers\Accounting\DonationCategoryController;
use App\Http\Controllers\Accounting\FinancialAccountController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\OperationalReportController;
use App\Http\Controllers\Accounting\OperationalTransactionController;
use App\Http\Controllers\Accounting\PurchaseBillController;
use App\Http\Controllers\Accounting\PurchaseOrderController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\Accounting\VendorController;
use App\Http\Controllers\Accounting\VoucherTypeController;
use App\Http\Controllers\AccountingSetup\AccountController;
use App\Http\Controllers\AccountingSetup\AccountImportController;
use App\Http\Controllers\AccountingSetup\AccountingPeriodController;
use App\Http\Controllers\AccountingSetup\AccountingReadinessController;
use App\Http\Controllers\AccountingSetup\AccountMappingController;
use App\Http\Controllers\AccountingSetup\FiscalYearController;
use App\Http\Controllers\AccountingSetup\OpeningBalanceController;
use App\Http\Controllers\AccountingSetup\OpeningBalanceImportController;
use App\Http\Controllers\AccountingSetup\YearEndClosingController;
use App\Http\Controllers\Administration\RoleController;
use App\Http\Controllers\Administration\UserController;
use App\Http\Controllers\ApprovalSettingsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Integration\FrcConnectionController;
use App\Http\Controllers\MasterData\BranchController;
use App\Http\Controllers\MasterData\FrcServiceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->middleware(['permission:users.create', 'permission:users.assign_roles', 'permission:branches.assign_users'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->middleware(['permission:users.create', 'permission:users.assign_roles', 'permission:branches.assign_users'])->name('users.store');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view')->name('users.show');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->middleware(['permission:users.update', 'permission:users.assign_roles', 'permission:branches.assign_users'])->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware(['permission:users.update', 'permission:users.assign_roles', 'permission:branches.assign_users'])->name('users.update');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view')->name('roles.index');
    Route::get('/roles/create', [RoleController::class, 'create'])->middleware(['permission:roles.create', 'permission:roles.assign_permissions'])->name('roles.create');
    Route::post('/roles', [RoleController::class, 'store'])->middleware(['permission:roles.create', 'permission:roles.assign_permissions'])->name('roles.store');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view')->name('roles.show');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->middleware(['permission:roles.update', 'permission:roles.assign_permissions'])->name('roles.edit');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware(['permission:roles.update', 'permission:roles.assign_permissions'])->name('roles.update');

    Route::get('/branches', [BranchController::class, 'index'])->middleware('permission:branches.view')->name('branches.index');
    Route::post('/branches/sync', [BranchController::class, 'sync'])->middleware(['permission:branches.sync', 'throttle:3,1'])->name('branches.sync');
    Route::get('/branches/{branch}', [BranchController::class, 'show'])->middleware(['permission:branches.view', 'branch.access'])->name('branches.show');

    Route::get('/services', [FrcServiceController::class, 'index'])->middleware('permission:services.view')->name('services.index');
    Route::post('/services/sync', [FrcServiceController::class, 'sync'])->middleware(['permission:services.sync', 'throttle:3,1'])->name('services.sync');

    Route::get('/settings', [SettingsController::class, 'edit'])->middleware('permission:settings.view')->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware('permission:settings.update')->name('settings.update');
    Route::get('/settings/approval-controls', [ApprovalSettingsController::class, 'edit'])->middleware('permission:approval_settings.manage')->name('approval-settings.edit');
    Route::put('/settings/approval-controls', [ApprovalSettingsController::class, 'update'])->middleware('permission:approval_settings.manage')->name('approval-settings.update');

    Route::get('/fiscal-years', [FiscalYearController::class, 'index'])->middleware('permission:fiscal_years.view')->name('fiscal-years.index');
    Route::get('/fiscal-years/create', [FiscalYearController::class, 'create'])->middleware('permission:fiscal_years.manage')->name('fiscal-years.create');
    Route::post('/fiscal-years', [FiscalYearController::class, 'store'])->middleware('permission:fiscal_years.manage')->name('fiscal-years.store');
    Route::get('/fiscal-years/{fiscalYear}', [FiscalYearController::class, 'show'])->middleware('permission:fiscal_years.view')->name('fiscal-years.show');
    Route::get('/fiscal-years/{fiscalYear}/edit', [FiscalYearController::class, 'edit'])->middleware('permission:fiscal_years.manage')->name('fiscal-years.edit');
    Route::put('/fiscal-years/{fiscalYear}', [FiscalYearController::class, 'update'])->middleware('permission:fiscal_years.manage')->name('fiscal-years.update');
    Route::post('/fiscal-years/{fiscalYear}/open', [FiscalYearController::class, 'open'])->middleware('permission:fiscal_years.manage')->name('fiscal-years.open');
    Route::post('/fiscal-years/{fiscalYear}/close', [FiscalYearController::class, 'close'])->middleware('permission:fiscal_years.close')->name('fiscal-years.close');
    Route::get('/fiscal-years/{fiscalYear}/closing', [YearEndClosingController::class, 'show'])->middleware('permission:fiscal_years.close')->name('fiscal-years.closing');
    Route::post('/fiscal-years/{fiscalYear}/closing', [YearEndClosingController::class, 'store'])->middleware(['permission:fiscal_years.close', 'permission:journals.post', 'permission:branches.view_all'])->name('fiscal-years.closing.store');
    Route::post('/fiscal-years/{fiscalYear}/reopen', [FiscalYearController::class, 'reopen'])->middleware('permission:fiscal_years.reopen')->name('fiscal-years.reopen');
    Route::post('/fiscal-years/{fiscalYear}/current', [FiscalYearController::class, 'current'])->middleware('permission:fiscal_years.manage')->name('fiscal-years.current');

    Route::get('/accounting-periods', [AccountingPeriodController::class, 'index'])->middleware('permission:accounting_periods.view')->name('accounting-periods.index');
    Route::get('/accounting-periods/{accountingPeriod}', [AccountingPeriodController::class, 'show'])->middleware('permission:accounting_periods.view')->name('accounting-periods.show');
    Route::post('/accounting-periods/{accountingPeriod}/open', [AccountingPeriodController::class, 'open'])->middleware('permission:accounting_periods.manage')->name('accounting-periods.open');
    Route::post('/accounting-periods/{accountingPeriod}/close', [AccountingPeriodController::class, 'close'])->middleware('permission:accounting_periods.close')->name('accounting-periods.close');
    Route::post('/accounting-periods/{accountingPeriod}/reopen', [AccountingPeriodController::class, 'reopen'])->middleware('permission:accounting_periods.reopen')->name('accounting-periods.reopen');

    Route::get('/accounts/import', [AccountImportController::class, 'create'])->middleware('permission:accounts.import')->name('account-imports.create');
    Route::post('/accounts/import', [AccountImportController::class, 'store'])->middleware('permission:accounts.import')->name('account-imports.store');
    Route::get('/accounts/import/{accountImport}', [AccountImportController::class, 'show'])->middleware('permission:accounts.import')->name('account-imports.show');
    Route::post('/accounts/import/{accountImport}/confirm', [AccountImportController::class, 'confirm'])->middleware('permission:accounts.import')->name('account-imports.confirm');
    Route::post('/accounts/import/{accountImport}/decide', [AccountImportController::class, 'decide'])->middleware('permission:accounts.import')->name('account-imports.decide');
    Route::get('/accounts/import/{accountImport}/export', [AccountImportController::class, 'export'])->middleware('permission:accounts.import')->name('account-imports.export');

    Route::get('/account-mappings', [AccountMappingController::class, 'index'])->middleware('permission:account_mappings.view')->name('account-mappings.index');
    Route::put('/account-mappings/services/{service}', [AccountMappingController::class, 'updateService'])->middleware('permission:account_mappings.manage')->name('account-mappings.services.update');
    Route::put('/account-mappings/{mappingKey}', [AccountMappingController::class, 'update'])->middleware('permission:account_mappings.manage')->name('account-mappings.update');
    Route::get('/accounting-setup/readiness', AccountingReadinessController::class)->middleware('permission:accounting_setup.readiness')->name('accounting-setup.readiness');
    Route::get('/accounts', [AccountController::class, 'index'])->middleware('permission:accounts.view')->name('accounts.index');
    Route::get('/accounts/create', [AccountController::class, 'create'])->middleware('permission:accounts.create')->name('accounts.create');
    Route::post('/accounts', [AccountController::class, 'store'])->middleware('permission:accounts.create')->name('accounts.store');
    Route::get('/accounts/{account}', [AccountController::class, 'show'])->middleware('permission:accounts.view')->name('accounts.show');
    Route::get('/accounts/{account}/edit', [AccountController::class, 'edit'])->middleware('permission:accounts.update')->name('accounts.edit');
    Route::put('/accounts/{account}', [AccountController::class, 'update'])->middleware('permission:accounts.update')->name('accounts.update');
    Route::post('/accounts/{account}/activate', [AccountController::class, 'activate'])->middleware('permission:accounts.update')->name('accounts.activate');
    Route::post('/accounts/{account}/deactivate', [AccountController::class, 'deactivate'])->middleware('permission:accounts.deactivate')->name('accounts.deactivate');

    Route::get('/opening-balances/import', [OpeningBalanceImportController::class, 'create'])->middleware('permission:opening_balances.import')->name('opening-balance-imports.create');
    Route::post('/opening-balances/import', [OpeningBalanceImportController::class, 'store'])->middleware('permission:opening_balances.import')->name('opening-balance-imports.store');
    Route::get('/opening-balances/import/{openingBalanceImport}', [OpeningBalanceImportController::class, 'show'])->middleware('permission:opening_balances.import')->name('opening-balance-imports.show');
    Route::post('/opening-balances/import/{openingBalanceImport}/apply', [OpeningBalanceImportController::class, 'apply'])->middleware(['permission:opening_balances.import', 'permission:opening_balances.prepare'])->name('opening-balance-imports.apply');
    Route::get('/opening-balances', [OpeningBalanceController::class, 'index'])->middleware('permission:opening_balances.view')->name('opening-balances.index');
    Route::get('/opening-balances/create', [OpeningBalanceController::class, 'create'])->middleware('permission:opening_balances.prepare')->name('opening-balances.create');
    Route::post('/opening-balances', [OpeningBalanceController::class, 'store'])->middleware('permission:opening_balances.prepare')->name('opening-balances.store');
    Route::get('/opening-balances/{openingBalance}', [OpeningBalanceController::class, 'show'])->middleware('permission:opening_balances.view')->name('opening-balances.show');
    Route::post('/opening-balances/{openingBalance}/approve', [OpeningBalanceController::class, 'approve'])->middleware('permission:opening_balances.approve')->name('opening-balances.approve');
    Route::post('/opening-balances/{openingBalance}/post', [OpeningBalanceController::class, 'post'])->middleware('permission:opening_balances.post')->name('opening-balances.post');

    Route::get('/voucher-types', [VoucherTypeController::class, 'index'])->middleware('permission:voucher_types.view')->name('voucher-types.index');
    Route::get('/voucher-types/{voucherType}/edit', [VoucherTypeController::class, 'edit'])->middleware('permission:voucher_types.manage')->name('voucher-types.edit');
    Route::put('/voucher-types/{voucherType}', [VoucherTypeController::class, 'update'])->middleware('permission:voucher_types.manage')->name('voucher-types.update');

    Route::get('/journals', [JournalController::class, 'index'])->middleware('permission:journals.view')->name('journals.index');
    Route::get('/journals/create', [JournalController::class, 'create'])->middleware('permission:journals.create')->name('journals.create');
    Route::post('/journals', [JournalController::class, 'store'])->middleware('permission:journals.create')->name('journals.store');
    Route::get('/journals/{journal}', [JournalController::class, 'show'])->middleware('permission:journals.view')->name('journals.show');
    Route::get('/journals/{journal}/edit', [JournalController::class, 'edit'])->middleware('permission:journals.update')->name('journals.edit');
    Route::put('/journals/{journal}', [JournalController::class, 'update'])->middleware('permission:journals.update')->name('journals.update');
    Route::delete('/journals/{journal}', [JournalController::class, 'destroy'])->middleware('permission:journals.update')->name('journals.destroy');
    Route::post('/journals/{journal}/submit', [JournalController::class, 'submit'])->middleware('permission:journals.submit')->name('journals.submit');
    Route::post('/journals/{journal}/approve', [JournalController::class, 'approve'])->middleware('permission:journals.approve')->name('journals.approve');
    Route::post('/journals/{journal}/reject', [JournalController::class, 'reject'])->middleware('permission:journals.reject')->name('journals.reject');
    Route::post('/journals/{journal}/post', [JournalController::class, 'post'])->middleware('permission:journals.post')->name('journals.post');
    Route::post('/journals/{journal}/reverse', [JournalController::class, 'reverse'])->middleware('permission:journals.reverse')->name('journals.reverse');

    Route::get('/reports/general-ledger', GeneralLedgerController::class)->middleware('permission:ledgers.view')->name('reports.general-ledger');
    Route::get('/reports/trial-balance', TrialBalanceController::class)->middleware('permission:trial_balance.view')->name('reports.trial-balance');

    Route::resource('financial-accounts', FinancialAccountController::class)->except(['show', 'destroy'])->middleware('permission:financial_accounts.view');
    Route::post('/financial-accounts/{financialAccount}/toggle', [FinancialAccountController::class, 'toggle'])->middleware('permission:financial_accounts.manage')->name('financial-accounts.toggle');
    Route::resource('vendors', VendorController::class)->except('destroy')->middleware('permission:vendors.view');
    Route::post('/vendors/{vendor}/toggle', [VendorController::class, 'toggle'])->middleware('permission:vendors.deactivate')->name('vendors.toggle');
    Route::get('/donation-categories', [DonationCategoryController::class, 'index'])->middleware('permission:donations.manage_categories')->name('donation-categories.index');
    Route::put('/donation-categories/{donationCategory}', [DonationCategoryController::class, 'update'])->middleware('permission:donations.manage_categories')->name('donation-categories.update');

    Route::get('/finance/{type}', [OperationalTransactionController::class, 'index'])->whereIn('type', ['cash_receipt', 'bank_receipt', 'cash_payment', 'bank_payment', 'expense', 'petty_cash', 'donation', 'vendor_payment'])->name('transactions.index');
    Route::get('/finance/{type}/create', [OperationalTransactionController::class, 'create'])->whereIn('type', ['cash_receipt', 'bank_receipt', 'cash_payment', 'bank_payment', 'expense', 'petty_cash', 'donation', 'vendor_payment'])->name('transactions.create');
    Route::post('/finance', [OperationalTransactionController::class, 'store'])->name('transactions.store');
    Route::get('/finance/transaction/{transaction}', [OperationalTransactionController::class, 'show'])->name('transactions.show');
    Route::post('/finance/transaction/{transaction}/submit', [OperationalTransactionController::class, 'submit'])->name('transactions.submit');
    Route::post('/finance/transaction/{transaction}/approve', [OperationalTransactionController::class, 'approve'])->name('transactions.approve');
    Route::post('/finance/transaction/{transaction}/reject', [OperationalTransactionController::class, 'reject'])->name('transactions.reject');
    Route::post('/finance/transaction/{transaction}/disburse', [OperationalTransactionController::class, 'disburse'])->name('transactions.disburse');
    Route::post('/finance/transaction/{transaction}/post', [OperationalTransactionController::class, 'post'])->name('transactions.post');
    Route::post('/finance/transaction/{transaction}/reverse', [OperationalTransactionController::class, 'reverse'])->name('transactions.reverse');
    Route::get('/finance/transaction/{transaction}/acknowledgment', [OperationalTransactionController::class, 'acknowledgment'])->name('transactions.acknowledgment');

    Route::resource('purchase-bills', PurchaseBillController::class)->only(['index', 'create', 'store', 'show'])->middleware('permission:purchase_bills.view');
    Route::post('/purchase-bills/{purchaseBill}/submit', [PurchaseBillController::class, 'submit'])->name('purchase-bills.submit');
    Route::post('/purchase-bills/{purchaseBill}/approve', [PurchaseBillController::class, 'approve'])->name('purchase-bills.approve');
    Route::post('/purchase-bills/{purchaseBill}/reject', [PurchaseBillController::class, 'reject'])->name('purchase-bills.reject');
    Route::post('/purchase-bills/{purchaseBill}/post', [PurchaseBillController::class, 'post'])->name('purchase-bills.post');
    Route::post('/purchase-bills/{purchaseBill}/reverse', [PurchaseBillController::class, 'reverse'])->name('purchase-bills.reverse');

    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase_orders.view')->name('purchase-orders.index');
    Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->middleware('permission:purchase_orders.create')->name('purchase-orders.create');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase_orders.create')->name('purchase-orders.store');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase_orders.view')->name('purchase-orders.show');
    Route::get('/purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])->middleware('permission:purchase_orders.update')->name('purchase-orders.edit');
    Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchase_orders.update')->name('purchase-orders.update');
    Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchase_orders.submit')->name('purchase-orders.submit');
    Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchase_orders.approve|purchase_orders.approve_high_value')->name('purchase-orders.approve');
    Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->middleware('permission:purchase_orders.reject')->name('purchase-orders.reject');
    Route::post('/purchase-orders/{purchaseOrder}/issue', [PurchaseOrderController::class, 'issue'])->middleware('permission:purchase_orders.issue')->name('purchase-orders.issue');
    Route::post('/purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->middleware('permission:purchase_orders.close')->name('purchase-orders.close');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase_orders.cancel')->name('purchase-orders.cancel');

    Route::get('/reports/books/{kind}', [OperationalReportController::class, 'book'])->whereIn('kind', ['cash', 'bank'])->name('reports.books');
    Route::get('/reports/registers/{kind}', [OperationalReportController::class, 'register'])->whereIn('kind', ['receipts', 'payments', 'expenses', 'donations'])->middleware('permission:finance_reports.view')->name('reports.registers');
    Route::get('/reports/vendor-ledger', [OperationalReportController::class, 'vendorLedger'])->middleware('permission:finance_reports.view')->name('reports.vendor-ledger');
    Route::get('/reports/payables', [OperationalReportController::class, 'payables'])->middleware('permission:finance_reports.view')->name('reports.payables');

    foreach (['assets', 'inventory', 'budgets', 'payroll', 'statements', 'cashflow', 'ledger-exports', 'operational-reports', 'payroll-reports', 'frc-integration', 'fee-posting'] as $module) {
        require __DIR__.'/modules/'.$module.'.php';
    }

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::post('/integrations/frc/check', FrcConnectionController::class)
        ->middleware(['permission:services.sync', 'throttle:6,1'])
        ->name('integrations.frc.check');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
