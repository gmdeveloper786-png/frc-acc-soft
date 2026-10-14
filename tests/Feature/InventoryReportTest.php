<?php

use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Inventory\InventoryService;
use App\Services\PurchaseOrderService;
use App\Services\Reports\Operational\InventoryReportService;
use App\Services\Reports\Operational\OperationalReport;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Alpha Branch']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish', 'name' => 'Beta Branch']);
    $this->maker = inventoryreportUser(SystemRole::Accountant, $this->branch);
    $this->approver = inventoryreportUser(SystemRole::Approver, $this->branch);
    $this->mover = inventoryreportUser(SystemRole::Accountant, $this->branch, $this->otherBranch);
    $this->betaUser = inventoryreportUser(SystemRole::Accountant, $this->otherBranch);
    $this->admin = inventoryreportUser(SystemRole::Administrator, $this->branch);
    $this->location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Main store']);
    $this->otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id, 'name' => 'Beta store']);
    $this->item = InventoryItem::factory()->create(['item_code' => 'ITM-MAT', 'name' => 'Therapy mat', 'unit_of_measure' => 'pcs', 'minimum_stock_level' => '8.0000', 'created_by' => $this->maker->id]);
    $this->actingAs($this->maker);
});

function inventoryreportUser(SystemRole $role, Branch ...$branches): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    foreach ($branches as $branch) {
        $user->branches()->attach($branch);
    }

    return $user;
}

function inventoryreportPayload(object $test, string $quantity, string $date, array $overrides = []): array
{
    return ['item_id' => $test->item->id, 'branch_id' => $test->branch->id, 'location_id' => $test->location->id, 'quantity' => $quantity, 'transaction_date' => $date, ...$overrides];
}

function inventoryreportPurchaseOrder(Branch $branch): PurchaseOrder
{
    $buyer = inventoryreportUser(SystemRole::Accountant, $branch);
    $service = app(PurchaseOrderService::class);
    $order = $service->create([
        'vendor_id' => Vendor::factory()->create()->id, 'branch_id' => $branch->id, 'po_date' => '2026-01-05',
        'lines' => [['description' => 'Therapy mats', 'quantity' => '10.0000', 'unit_price' => '100.0000']],
    ], $buyer);
    $service->submit($order, $buyer);

    return $service->approve($order->fresh(), inventoryreportUser(SystemRole::Director, $branch));
}

/**
 * January 2026 at Alpha / Main store: running 10, 6, 11, 9.5, 9, then a
 * transfer of 2 to Beta (7) and an approved adjustment of +0.25 (7.25).
 * A pending (-1) and a rejected (-3) adjustment never touch the balance.
 *
 * @return array<string, mixed>
 */
function inventoryreportSeedStock(object $test): array
{
    $service = app(InventoryService::class);
    $order = inventoryreportPurchaseOrder($test->branch);
    $service->receive(inventoryreportPayload($test, '10', '2026-01-10', ['reference' => 'GRN-1', 'purchase_order_id' => $order->id]), $test->maker);
    $service->issue(inventoryreportPayload($test, '4', '2026-01-15', ['issued_to' => 'Ward A', 'reference' => 'ISS-1']), $test->maker);
    $service->receive(inventoryreportPayload($test, '5', '2026-01-20', ['reference' => 'GRN-2']), $test->maker);
    $service->issue(inventoryreportPayload($test, '1.5', '2026-01-25', ['issued_to' => '=cmd|calc']), $test->maker);
    $service->issue(inventoryreportPayload($test, '0.5', '2026-01-26', ['issued_to' => 'Ward A']), $test->maker);
    $service->transfer([
        'item_id' => $test->item->id, 'from_branch_id' => $test->branch->id, 'from_location_id' => $test->location->id,
        'to_branch_id' => $test->otherBranch->id, 'to_location_id' => $test->otherLocation->id, 'quantity' => '2', 'transaction_date' => '2026-01-28', 'reference' => 'TRF-1',
    ], $test->mover);
    $pending = $service->requestAdjustment(inventoryreportPayload($test, '1', '2026-01-29', ['direction' => 'decrease', 'notes' => 'Damaged in storage']), $test->maker);
    $approved = $service->approveAdjustment($service->requestAdjustment(inventoryreportPayload($test, '0.25', '2026-01-29', ['direction' => 'increase', 'notes' => 'Found in store room']), $test->maker), $test->approver);
    $rejected = $service->rejectAdjustment($service->requestAdjustment(inventoryreportPayload($test, '3', '2026-01-30', ['direction' => 'decrease', 'notes' => 'Count difference']), $test->maker), 'No count sheet', $test->approver);

    return ['order' => $order, 'pending' => $pending, 'approved' => $approved, 'rejected' => $rejected];
}

function inventoryreportBuild(string $report, User $user, array $filters = []): OperationalReport
{
    return app(InventoryReportService::class)->build($report, $user, ['date_from' => '2026-01-01', 'date_to' => '2026-01-31', ...$filters]);
}

test('stock balance equals the sum of posted movements and flags any mismatch', function () {
    inventoryreportSeedStock($this);

    $report = inventoryreportBuild('stock-balance', $this->maker);

    expect($report->rows)->toHaveCount(1)
        ->and($report->rows[0])->toMatchArray(['item_code' => 'ITM-MAT', 'branch' => 'Alpha Branch', 'location' => 'Main store', 'quantity' => '7.2500', 'posted' => '7.2500', 'check' => 'Match'])
        ->and($report->rows[0]['quantity'])->toBe(app(InventoryService::class)->postedQuantity($this->item->id, $this->branch->id, $this->location->id))
        ->and($report->totals[0])->toMatchArray(['quantity' => '7.2500', 'posted' => '7.2500'])
        ->and($report->warnings)->toBe([])
        ->and($report->note)->toContain('Verified');
    $this->get(route('reports.inventory.stock-balance'))->assertOk()->assertSee('7.2500')->assertSee('Match')->assertDontSee('MISMATCH');
    $this->get(route('reports.inventory.index'))->assertOk()->assertSee('Stock Adjustment Audit');

    // A balance that drifts from its ledger, and movements without any balance row, are both flagged.
    DB::table('inventory_balances')->where('branch_id', $this->branch->id)->update(['quantity' => '9.0000']);
    $tampered = inventoryreportBuild('stock-balance', $this->maker);
    expect($tampered->rows[0])->toMatchArray(['quantity' => '9.0000', 'posted' => '7.2500', 'check' => 'MISMATCH'])
        ->and($tampered->warnings)->toHaveCount(1);
    $this->get(route('reports.inventory.stock-balance'))->assertOk()->assertSee('MISMATCH')->assertSee('do not equal the sum of their posted movements');

    DB::table('inventory_balances')->where('branch_id', $this->branch->id)->delete();
    $orphan = inventoryreportBuild('stock-balance', $this->maker);
    expect($orphan->rows[0])->toMatchArray(['quantity' => '', 'posted' => '7.2500', 'check' => 'MISMATCH — no balance row']);
});

test('movement register keeps a running quantity and totals, and respects the date range', function () {
    inventoryreportSeedStock($this);

    $report = inventoryreportBuild('movements', $this->maker);

    expect(array_column($report->rows, 'running'))->toBe(['10.0000', '6.0000', '11.0000', '9.5000', '9.0000', '7.0000', '7.2500'])
        ->and(array_column($report->rows, 'type'))->toBe(['Receipt', 'Issue', 'Receipt', 'Issue', 'Issue', 'Transfer out', 'Adjustment'])
        ->and($report->rows[1])->toMatchArray(['quantity_in' => '', 'quantity_out' => '4.0000', 'issued_to' => 'Ward A'])
        ->and($report->totals[0])->toMatchArray(['quantity_in' => '15.2500', 'quantity_out' => '8.0000']);

    // Both boundary dates are included and the running quantity carries the earlier stock forward.
    $window = inventoryreportBuild('movements', $this->maker, ['date_from' => '2026-01-15', 'date_to' => '2026-01-20']);
    $issuesOnly = inventoryreportBuild('movements', $this->maker, ['type' => 'issue']);

    expect(array_column($window->rows, 'date'))->toBe(['15 Jan 2026', '20 Jan 2026'])
        ->and(array_column($window->rows, 'running'))->toBe(['6.0000', '11.0000'])
        ->and($window->totals[0])->toMatchArray(['quantity_in' => '5.0000', 'quantity_out' => '4.0000'])
        ->and(inventoryreportBuild('movements', $this->maker, ['date_from' => '2026-01-16', 'date_to' => '2026-01-19'])->rows)->toBe([])
        ->and(array_column($issuesOnly->rows, 'running'))->toBe(['6.0000', '9.5000', '9.0000'])
        ->and($issuesOnly->totals[0]['quantity_out'])->toBe('6.0000');

    $this->get(route('reports.inventory.movements', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']))->assertOk()->assertSee('Transfer out')->assertSee('7.2500');
    $this->get(route('reports.inventory.movements', ['date_from' => '2026-02-01', 'date_to' => '2026-01-01']))->assertSessionHasErrors('date_to');
});

test('receive and issue registers and the issued-to history', function () {
    $seed = inventoryreportSeedStock($this);

    $receipts = inventoryreportBuild('receipts', $this->maker);
    $issues = inventoryreportBuild('issues', $this->maker);
    $issuedTo = collect(inventoryreportBuild('issued-to', $this->maker)->rows)->keyBy('issued_to');

    expect(array_column($receipts->rows, 'quantity'))->toBe(['10.0000', '5.0000'])
        ->and($receipts->rows[0])->toMatchArray(['reference' => 'GRN-1', 'purchase_order' => $seed['order']->po_number, 'recorded_by' => $this->maker->name])
        ->and($receipts->rows[1]['purchase_order'])->toBe('')
        ->and($receipts->totals[0]['quantity'])->toBe('15.0000')
        ->and(array_column($issues->rows, 'quantity'))->toBe(['4.0000', '1.5000', '0.5000'])
        ->and(array_column($issues->rows, 'issued_to'))->toBe(['Ward A', '=cmd|calc', 'Ward A'])
        ->and($issues->totals[0]['quantity'])->toBe('6.0000')
        ->and($issuedTo['Ward A'])->toMatchArray(['issues' => 2, 'quantity' => '4.5000', 'first_date' => '15 Jan 2026', 'last_date' => '26 Jan 2026'])
        ->and($issuedTo['=cmd|calc'])->toMatchArray(['issues' => 1, 'quantity' => '1.5000'])
        ->and(inventoryreportBuild('issues', $this->maker, ['issued_to' => 'Ward'])->rows)->toHaveCount(2)
        ->and(inventoryreportBuild('issued-to', $this->maker, ['issued_to' => 'Ward'])->rows)->toHaveCount(1)
        ->and(inventoryreportBuild('issues', $this->maker, ['date_from' => '2026-01-26', 'date_to' => '2026-01-26'])->rows)->toHaveCount(1);

    $this->get(route('reports.inventory.receipts', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']))->assertOk()->assertSee($seed['order']->po_number);
    $this->get(route('reports.inventory.issued-to', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']))->assertOk()->assertSee('Ward A')->assertSee('4.5000');
});

test('transfer register pairs both legs with equal quantities', function () {
    inventoryreportSeedStock($this);

    $report = inventoryreportBuild('transfers', $this->maker);

    expect($report->rows)->toHaveCount(1)
        ->and($report->rows[0])->toMatchArray([
            'from_branch' => 'Alpha Branch', 'from_location' => 'Main store', 'to_branch' => 'Beta Branch', 'to_location' => 'Beta store',
            'quantity_out' => '2.0000', 'quantity_in' => '2.0000', 'check' => 'Paired', 'reference' => 'TRF-1',
        ])
        ->and($report->totals[0])->toMatchArray(['quantity_out' => '2.0000', 'quantity_in' => '2.0000'])
        ->and($report->warnings)->toBe([])
        // The receiving branch sees the same paired transfer.
        ->and(inventoryreportBuild('transfers', $this->betaUser)->rows[0])->toMatchArray(['quantity_out' => '2.0000', 'quantity_in' => '2.0000', 'check' => 'Paired'])
        ->and(inventoryreportBuild('transfers', $this->maker, ['date_from' => '2026-01-01', 'date_to' => '2026-01-27'])->rows)->toBe([]);
    $this->get(route('reports.inventory.transfers', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']))->assertOk()->assertSee('Paired')->assertSee('Beta store');
});

test('low stock report lists items at or below their minimum level', function () {
    inventoryreportSeedStock($this);
    $plenty = InventoryItem::factory()->create(['minimum_stock_level' => '1.0000']);
    app(InventoryService::class)->receive(inventoryreportPayload($this, '50', '2026-01-10', ['item_id' => $plenty->id]), $this->maker);

    $report = inventoryreportBuild('low-stock', $this->maker);

    expect($report->rows)->toHaveCount(1)
        ->and($report->rows[0])->toMatchArray(['item_code' => 'ITM-MAT', 'branch' => 'Alpha Branch', 'quantity' => '7.2500', 'minimum' => '8.0000', 'shortfall' => '0.7500'])
        ->and(app(InventoryService::class)->isLowStock($this->item, $this->branch))->toBeTrue()
        ->and(inventoryreportBuild('low-stock', $this->betaUser)->rows[0])->toMatchArray(['branch' => 'Beta Branch', 'quantity' => '2.0000', 'shortfall' => '6.0000']);
    $this->get(route('reports.inventory.low-stock'))->assertOk()->assertSee('ITM-MAT')->assertSee('0.7500');

    $branchSummary = inventoryreportBuild('branch-summary', $this->maker);
    expect(collect($branchSummary->rows)->firstWhere('item_code', 'ITM-MAT'))->toMatchArray(['branch' => 'Alpha Branch', 'locations' => 1, 'quantity' => '7.2500'])
        ->and($branchSummary->rows)->toHaveCount(2);
    $this->get(route('reports.inventory.branch-summary'))->assertOk()->assertSee('7.2500');
});

test('adjustment audit shows pending, approved and rejected requests with their actors', function () {
    $seed = inventoryreportSeedStock($this);

    $rows = collect(inventoryreportBuild('adjustments', $this->maker)->rows)->keyBy('status');

    expect($rows)->toHaveCount(3)
        ->and($rows['Pending'])->toMatchArray(['quantity' => '-1.0000', 'reason' => 'Damaged in storage', 'recorded_by' => $this->maker->name, 'approved_by' => '', 'rejected_by' => ''])
        ->and($rows['Approved'])->toMatchArray(['quantity' => '0.2500', 'reason' => 'Found in store room', 'approved_by' => $this->approver->name, 'rejected_by' => ''])
        ->and($rows['Rejected'])->toMatchArray(['quantity' => '-3.0000', 'approved_by' => '', 'rejected_by' => $this->approver->name, 'rejection_reason' => 'No count sheet'])
        ->and(inventoryreportBuild('adjustments', $this->maker, ['status' => 'pending'])->rows)->toHaveCount(1)
        // Pending and rejected requests are listed here only: they change no balance and appear in no other register.
        ->and($seed['pending']->fresh()->status->value)->toBe('pending')
        ->and(inventoryreportBuild('stock-balance', $this->maker)->rows[0]['quantity'])->toBe('7.2500')
        ->and(collect(inventoryreportBuild('movements', $this->maker)->rows)->where('type', 'Adjustment'))->toHaveCount(1);
    $this->get(route('reports.inventory.adjustments', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']))->assertOk()->assertSee('No count sheet')->assertSee('Pending');
});

test('inventory reports enforce permissions and branch scope', function () {
    inventoryreportSeedStock($this);
    $stranger = User::factory()->create();
    $inactive = inventoryreportUser(SystemRole::Accountant, $this->branch);
    $inactive->update(['is_active' => false]);

    $this->actingAs($stranger)->get(route('reports.inventory.stock-balance'))->assertForbidden();
    $this->get(route('reports.inventory.index'))->assertForbidden();
    expect(fn () => inventoryreportBuild('stock-balance', $stranger))->toThrow(HttpException::class)
        ->and(fn () => inventoryreportBuild('stock-balance', $inactive))->toThrow(HttpException::class);

    $this->actingAs($this->maker)->get(route('reports.inventory.stock-balance', ['branch_id' => $this->otherBranch->id]))->assertForbidden();
    $this->get(route('reports.inventory.movements', ['branch_id' => 'all']))->assertForbidden();
    $this->get(route('reports.inventory.adjustments.export', ['format' => 'csv', 'branch_id' => $this->otherBranch->id]))->assertForbidden();

    $own = inventoryreportBuild('stock-balance', $this->maker);
    $all = inventoryreportBuild('stock-balance', $this->admin, ['branch_id' => 'all']);
    expect(array_column($own->rows, 'branch'))->toBe(['Alpha Branch'])
        ->and(array_column(inventoryreportBuild('movements', $this->maker)->rows, 'branch'))->not->toContain('Beta Branch')
        ->and(array_column($all->rows, 'branch'))->toBe(['Alpha Branch', 'Beta Branch'])
        ->and($all->totals[0]['quantity'])->toBe('9.2500')
        ->and(array_column(inventoryreportBuild('stock-balance', $this->betaUser)->rows, 'quantity'))->toBe(['2.0000']);
    $this->actingAs($this->admin)->get(route('reports.inventory.stock-balance', ['branch_id' => 'all']))->assertOk()->assertSee('Beta store');
});

test('export needs the export permission and keeps 4-decimal quantities and neutralises formulas', function () {
    inventoryreportSeedStock($this);
    $range = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];

    // The Approver may view inventory but holds no inventory_reports.export.
    $this->actingAs($this->approver)->get(route('reports.inventory.stock-balance'))->assertOk()->assertDontSee('/export/csv');
    $this->get(route('reports.inventory.stock-balance.export', ['format' => 'csv']))->assertForbidden();
    expect(fn () => app(InventoryReportService::class)->export('stock-balance', $this->approver, []))->toThrow(HttpException::class);
    $this->actingAs($this->maker)->get(route('reports.inventory.stock-balance.export', ['format' => 'docx']))->assertNotFound();

    $balance = $this->get(route('reports.inventory.stock-balance.export', ['format' => 'csv']))->assertOk()->streamedContent();
    $issues = $this->get(route('reports.inventory.issues.export', ['format' => 'csv'] + $range))->assertOk()->streamedContent();
    $issuedTo = $this->get(route('reports.inventory.issued-to.export', ['format' => 'csv'] + $range))->assertOk()->streamedContent();

    expect($balance)->toContain('Current Stock Balance')->toContain('Branch: Alpha Branch')->toContain('ITM-MAT')->toContain(',7.2500,7.2500,Match')->toContain(',,,,,7.2500,7.2500,')
        ->and($issues)->toContain('Period: 01 Jan 2026 to 31 Jan 2026')->toContain("'=cmd|calc,1.5000")->toContain(',4.0000,')->toContain(',6.0000,')
        ->and($issues)->not->toContain(',=cmd|calc')
        ->and($issuedTo)->toContain("'=cmd|calc,ITM-MAT")->toContain(',2,4.5000,');

    $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
    file_put_contents($path, $this->get(route('reports.inventory.movements.export', ['format' => 'xlsx'] + $range))->assertOk()->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet();
    $rows = collect($sheet->toArray(null, false, false, false));
    unlink($path);
    $header = $rows->search(fn (array $row): bool => $row[0] === 'Date');
    $formula = $rows->first(fn (array $row): bool => $row[8] === '=cmd|calc');
    expect($rows[$header + 1][11])->toEqual(10)
        ->and($rows[$header + 7][11])->toEqual(7.25)
        ->and($rows->last()[9])->toEqual(15.25)
        ->and($formula[10])->toEqual(1.5)
        ->and($sheet->getCell('I'.($rows->search($formula) + 1))->getDataType())->toBe('s')
        ->and($sheet->getStyle('L'.($header + 2))->getNumberFormat()->getFormatCode())->toBe('#,##0.0000');

    $pdf = $this->get(route('reports.inventory.transfers.export', ['format' => 'pdf'] + $range))->assertOk()->streamedContent();
    expect(str_starts_with($pdf, '%PDF-1.4'))->toBeTrue()
        ->and($pdf)->toContain('(Branch Transfer Register)')->toContain('(2.0000)')->toContain('(28 Jan 2026 / Paired / TRF-1)')->toContain('(Alpha Branch / Main store)')
        ->and(AuditLog::query()->where('event', 'report.exported')->count())->toBe(5);
});

test('no inventory report creates a journal, writes stock or shows a price or value column', function () {
    inventoryreportSeedStock($this);
    $before = [InventoryMovement::query()->count(), InventoryBalance::query()->pluck('quantity', 'id')->all(), InventoryMovement::query()->max('updated_at')];

    foreach (array_keys(InventoryReportService::REPORTS) as $report) {
        $labels = implode(' | ', array_column(inventoryreportBuild($report, $this->maker)->columns, 'label'));
        expect($labels)->not->toMatch('/price|value|cost|amount|rate|cogs|total/i');

        $this->get(route('reports.inventory.'.$report, ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']))->assertOk();
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $content = $this->get(route('reports.inventory.'.$report.'.export', ['format' => $format, 'date_from' => '2026-01-01', 'date_to' => '2026-01-31']))->assertOk()->streamedContent();
            if ($format === 'csv') {
                expect($content)->not->toMatch('/unit price|unit cost|stock value|cogs/i');
            }
            if ($format === 'pdf') {
                // No quantity may be cut short on the PDF page.
                expect($content)->not->toMatch('/\(-?[\d,.]+\.\.\.\)/');
            }
        }
    }

    // The only journal-free guarantee that matters: inventory never reaches the ledger.
    expect(JournalEntry::query()->count())->toBe(0)
        ->and(JournalLine::query()->count())->toBe(0)
        ->and([InventoryMovement::query()->count(), InventoryBalance::query()->pluck('quantity', 'id')->all(), InventoryMovement::query()->max('updated_at')])->toBe($before);
});
