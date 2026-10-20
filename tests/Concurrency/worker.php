<?php

/*
 * One concurrent actor. Usage: php worker.php <scenario> <base64 json arguments>.
 * Waits until the shared start time so that all workers overlap, runs a single
 * service call inside the disposable database, and prints a JSON result.
 */

use App\Models\AccountingPeriod;
use App\Models\DepreciationRun;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Assets\DepreciationRunService;
use App\Services\Inventory\InventoryService;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\OpeningBalancePostingService;
use App\Services\OperationalTransactionService;
use App\Services\Payroll\PayrollRunService;
use App\Services\PurchaseBillService;
use App\Services\PurchaseOrderService;

require __DIR__.'/bootstrap.php';
concurrencyBoot(dirname(__DIR__, 2));

$scenario = $argv[1] ?? '';
$arguments = json_decode((string) base64_decode($argv[2] ?? ''), true) ?: [];
$actor = User::query()->findOrFail($arguments['user_id']);
auth()->setUser($actor);

$wait = (float) ($arguments['start_at'] ?? 0) - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    $result = match ($scenario) {
        'inventory_issue' => app(InventoryService::class)->issue($arguments['payload'], $actor)->id,
        'inventory_transfer' => app(InventoryService::class)->transfer($arguments['payload'], $actor)['out']->id,
        'purchase_order' => app(PurchaseOrderService::class)->create($arguments['payload'], $actor)->po_number,
        'journal_post' => app(JournalPostingService::class)->post(JournalEntry::query()->findOrFail($arguments['journal_id']), $actor)->voucher_number,
        'purchase_bill' => app(PurchaseBillService::class)->create($arguments['payload'], $actor)->id,
        'vendor_payment' => app(OperationalTransactionService::class)->create($arguments['payload'], $actor)->id,
        'payroll_pay' => app(PayrollRunService::class)->pay(PayrollRun::query()->findOrFail($arguments['run_id']), $arguments['payload'], $actor)->id,
        'depreciation_post' => JournalEntry::query()->findOrFail(app(DepreciationRunService::class)->post(DepreciationRun::query()->findOrFail($arguments['run_id']), $actor)->journal_entry_id)->voucher_number,
        'depreciation_prepare' => app(DepreciationRunService::class)->prepare((int) $arguments['branch_id'], (int) $arguments['period_id'], $actor)->id,
        'reversal_request' => app(JournalReversalService::class)->request(JournalEntry::query()->findOrFail($arguments['journal_id']), AccountingPeriod::query()->findOrFail($arguments['period_id']), $arguments['date'], 'Concurrent reversal request test', $actor)->id,
        'reversal_post' => app(JournalReversalService::class)->post(JournalEntry::query()->findOrFail($arguments['reversal_id']), $actor)->voucher_number,
        'opening_post' => app(OpeningBalancePostingService::class)->post(OpeningBalanceBatch::query()->findOrFail($arguments['batch_id']), $actor)->journal_entry_id,
        'opening_return' => app(OpeningBalancePostingService::class)->returnToDraft(OpeningBalanceBatch::query()->findOrFail($arguments['batch_id']), 'Concurrent return to draft test', $actor)->status,
        default => throw new InvalidArgumentException('Unknown scenario.'),
    };
    echo json_encode(['ok' => true, 'result' => $result]);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'error' => class_basename($exception), 'message' => mb_substr($exception->getMessage(), 0, 160)]);
}
