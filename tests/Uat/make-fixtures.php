<?php

/*
 * Writes the SYNTHETIC spreadsheets uploaded by the release UAT
 * (tests/Uat/browser/release.mjs). No real names, salaries or bank details.
 *
 *   php tests/Uat/make-fixtures.php [outputDir]   (default storage/uat/fixtures)
 *
 *  - coa-import.xlsx: a small Chart of Accounts workbook with the client
 *    workbook's columns and NEW codes under the synthetic UAT chart, one
 *    School-entity row and one inter-unit row.
 *  - payroll-import.xlsx: a payroll sheet for two synthetic Branch B
 *    employees plus one unknown employee code (to be excluded in review).
 *  - uat-bank-statement-sample.csv: a short October 2026 statement for
 *    UAT Bank A (opening balance line, a deposit, a cheque, bank charges)
 *    for practising statement import and reconciliation matching.
 *
 * Only PhpSpreadsheet is used; the application and its database are not booted.
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$out = $argv[1] ?? $root.'/storage/uat/fixtures';
if (! is_dir($out) && ! mkdir($out, 0777, true) && ! is_dir($out)) {
    fwrite(STDERR, "Cannot create {$out}\n");
    exit(1);
}

$write = function (string $file, string $sheetName, array $rows): void {
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->setTitle($sheetName);
    $sheet->fromArray($rows, null, 'A1', true);
    (new Xlsx($book))->save($file);
    $book->disconnectWorksheets();
};

$coa = [
    ['Account Code', 'Account Name', 'Level', 'Parent Code', 'Account Type', 'Normal Balance', 'Financial Statement', 'Entity', 'Posting Allowed', 'Notes'],
    ['115', 'UAT Prepayments', 'Sub-group', '11', 'Asset', 'Debit', 'Balance Sheet', 'Both', 'No', 'Synthetic UAT heading'],
    ['11501', 'UAT Prepaid Rent', 'Ledger', '115', 'Asset', 'Debit', 'Balance Sheet', 'Both', 'Yes', 'Synthetic UAT ledger'],
    ['11502', 'UAT Prepaid Insurance', 'Ledger', '115', 'Asset', 'Debit', 'Balance Sheet', 'Rehab Centre', 'Yes', 'Synthetic UAT ledger'],
    ['11503', 'UAT Inter-unit Current Account', 'Ledger', '115', 'Asset', 'Debit', 'Balance Sheet', 'Both', 'Yes', 'Synthetic inter-unit balance'],
    ['51105', 'UAT School Stationery', 'Ledger', '511', 'Expense', 'Debit', 'Income & Expenditure', 'School', 'Yes', 'Synthetic School-entity ledger'],
];
$write($out.'/coa-import.xlsx', 'Chart of Accounts', $coa);

$payroll = [
    ['Employee Code', 'Employee Name', 'Basic Salary', 'Allowance', 'Tax', 'Net Salary'],
    ['UAT-EMP-B01', 'UAT Employee B One', 40000, 5000, 2000, 43000],
    ['UAT-EMP-B02', 'UAT Employee B Two', 30000, 3000, 1000, 32000],
    ['UAT-EMP-B99', 'UAT Unknown Person', 10000, 0, 0, 10000],
];
$write($out.'/payroll-import.xlsx', 'Payroll', $payroll);

$statement = [
    ['Date', 'Description', 'Reference', 'Withdrawal', 'Deposit', 'Balance'],
    ['2026-10-01', 'UAT opening balance', '', '', '', '0.00'],
    ['2026-10-05', 'UAT synthetic client deposit', 'UAT-DEP-001', '', '7000.00', '7000.00'],
    ['2026-10-12', 'UAT cheque payment', 'UAT-CHQ-0001', '2000.00', '', '5000.00'],
    ['2026-10-31', 'UAT bank charges', 'UAT-CHG-001', '150.00', '', '4850.00'],
];
$handle = fopen($out.'/uat-bank-statement-sample.csv', 'wb');
foreach ($statement as $row) {
    fputcsv($handle, $row, ',', '"', '');
}
fclose($handle);

echo "Fixtures written to {$out}".PHP_EOL;
