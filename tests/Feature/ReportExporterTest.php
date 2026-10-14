<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportTable;
use Database\Seeders\AppSettingSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    $this->actingAs(User::factory()->create(['name' => 'Report Tester']));
});

function exporterTable(int $rows = 3): ReportTable
{
    $data = [['account' => 'Expenses', 'amount' => null, '_style' => 'heading']];
    for ($i = 1; $i <= $rows; $i++) {
        $data[] = ['account' => $i === 1 ? '=HYPERLINK("http://evil.test","Rent")' : 'Ledger '.$i, 'amount' => '1234567.8900', '_indent' => 1];
    }
    $data[] = ['account' => 'Total', 'amount' => bcmul('1234567.8900', (string) $rows, 4), '_style' => 'total'];

    return new ReportTable('Income & Expenditure', [['key' => 'account', 'label' => 'Account'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']], $data, ['Branch' => '+All branches', 'Period' => '01 Jan 2026 to 31 Jan 2026'], 'Posted journals only.');
}

function exporterContent(string $format, int $rows = 3): string
{
    $response = app(ReportExporter::class)->download(exporterTable($rows), $format, 'income expenditure');
    expect($response->headers->get('content-disposition'))->toContain('income-expenditure-')->toContain('.'.$format)
        ->and($response->headers->get('cache-control'))->toContain('no-store');
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

test('csv export keeps exact values, identity and filters and neutralises formulas', function () {
    $csv = exporterContent('csv');

    expect($csv)->toContain('Faizan Rehabilitation Centre')->toContain('Income & Expenditure')
        ->toContain('Branch: +All branches')->toContain('Generated: ')->toContain('by Report Tester')->toContain('Currency: PKR')
        ->toContain("\"  '=HYPERLINK(\"\"http://evil.test\"\",\"\"Rent\"\")\"")
        ->toContain('1234567.8900')->toContain('Total,3703703.6700')
        ->and(AuditLog::query()->where('event', 'report.exported')->first()->properties['format'])->toBe('csv');
});

test('xlsx export writes numeric money cells and text that is never a formula', function () {
    $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
    file_put_contents($path, exporterContent('xlsx'));
    $sheet = IOFactory::load($path)->getActiveSheet();
    $rows = collect($sheet->toArray(null, false, false, false));
    $ledger = $rows->first(fn (array $row): bool => str_contains((string) $row[0], 'HYPERLINK'));
    $total = $rows->first(fn (array $row): bool => $row[0] === 'Total');

    expect($rows[0][0])->toBe('Faizan Rehabilitation Centre')
        ->and($ledger[1])->toEqual(1234567.89)
        ->and($total[1])->toEqual(3703703.67)
        ->and($sheet->getCell('A'.($rows->search($ledger) + 1))->getDataType())->toBe('s')
        ->and($sheet->getCell('B'.($rows->search($total) + 1))->getDataType())->toBe('n');
    unlink($path);
});

test('pdf export is a real paginated pdf document with the report content', function () {
    $pdf = exporterContent('pdf', 120);

    expect(str_starts_with($pdf, '%PDF-1.4'))->toBeTrue()
        ->and(str_ends_with($pdf, '%%EOF'))->toBeTrue()
        ->and($pdf)->toContain('(Faizan Rehabilitation Centre)')->toContain('(Income & Expenditure)')
        ->toContain('(1,234,567.89)')->toContain('(148,148,146.80)')->toContain('/Type /Page ')
        ->and(substr_count($pdf, '/Type /Page '))->toBeGreaterThan(2)
        ->and($pdf)->toContain('(Page 1 of '.substr_count($pdf, '/Type /Page ').')');
});

test('an unknown export format is not found', function () {
    app(ReportExporter::class)->download(exporterTable(), 'docx', 'x');
})->throws(NotFoundHttpException::class);
