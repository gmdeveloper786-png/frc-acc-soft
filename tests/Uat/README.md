# Isolated UAT

Everything here runs against a separate database named `<DB_DATABASE>_uat` and a synthetic FRC API. The scripts refuse any database name without the `_uat` suffix. No real data is created or read.

## 1. Build the UAT database

```
E:\wamp64\bin\php\php8.3.14\php.exe tests/Uat/setup.php --fresh
```

This creates synthetic branches, services, users (`uat.{admin,accountant,cashier,approver,director,board,auditor,branchb}@uat.test`, password `Uat-Password-2026!`), a synthetic UAT Director, a synthetic Chart of Accounts named "UAT …", mappings and an open FY 2026. `workflows.mjs` runs this step itself unless `--no-reset` is passed.

`tests/Uat/sample-data.php` (included by `setup.php`) then adds master data for a full **manual** test, through the application services as `uat.accountant` (ledgers as `uat.admin`):

| Module | Seeded |
|---|---|
| Ledgers | 11205 UAT Staff Advances (under 113), 12103 UAT Office Equipment (121), 51106 UAT Medical Supplies (511). 51105 stays free for `coa-import.xlsx` |
| Funds | Workbook codes GEN, DNR, ZKT, GRT, CAP; GRT-01 UAT Synthetic Grant (UAT Donor Foundation, 500,000.00, 2026-01-01..2026-12-31). Permitted ledgers: DNR 12101 + 51101, GRT-01 51101 + 51104, CAP 12101; **ZKT has none on purpose**. Donation categories: General → GEN, Zakat → ZKT, New FRC Project → CAP |
| Petty cash | Imprest float UAT Branch A, ledger 11204, 20,000.00, source UAT Bank A, custodian UAT Custodian |
| Payroll | Components HOUSE (House Allowance, earning) and STAFF_ADV (Staff Advance Recovery, deduction → 11205) next to the default ones; no tax rates. Employees UAT-E-001 (50,000 + 10,000), UAT-E-002 (40,000 + 8,000) in Branch A, UAT-E-003 (35,000 + 7,000) in Branch B. No payroll runs |
| Inventory | Category UAT Clinical Consumables; locations UAT Store A / UAT Store B; UAT-INV-001 UAT Gloves (box) 50 and UAT-INV-002 UAT Therapy Putty (unit) 30 received in UAT Store A (quantity only) |
| Fixed assets | Category UAT-THER UAT Therapy Equipment (12101 / 12102 / 51103, 60 months); ACTIVE asset UAT Therapy Mat Set 120,000.00, in service 2026-01-01, Branch A |
| Budgets | DRAFT "UAT Draft Budget Branch A 2026": 51101 60,000, 51104 12,000, 51102 1,080,000 a year, spread equally |
| Drafts | Expense 3,000.00 on 51104 (accrued, Branch A); cash payment 5,000.00 from UAT Cash A on 51101 with fund GRT-01 |

Posting policies (fees, depreciation, payroll) stay **unapproved**, nothing is submitted, approved or posted (0 journals). The script prints a summary with counts. Use `--no-sample` to build only the base data. The browser scripts (`workflows.mjs`, `release.mjs`) were written against the base data and call `setup.php --fresh` without that flag, so their expectations may not hold on the extended seed.

Read-only check of the result (counts, pending migrations, posted journals, Director users, policy flags):

```
E:\wamp64\bin\php\php8.3.14\php.exe tests/Uat/check-uat.php
```

Synthetic upload files (COA workbook, payroll workbook and `uat-bank-statement-sample.csv`, an October 2026 statement for UAT Bank A) are regenerated into `storage/uat/fixtures/` with:

```
E:\wamp64\bin\php\php8.3.14\php.exe tests/Uat/make-fixtures.php
```

## 2. Start the servers (PowerShell, from the project root)

```
$php = "E:\wamp64\bin\php\php8.3.14\php.exe"; $root = (Get-Location).Path
$env:DB_DATABASE = 'frc_accounting_software_uat'; $env:APP_URL = 'http://127.0.0.1:8010'
$env:FRC_API_BASE_URL = 'http://127.0.0.1:8011/api'; $env:FRC_API_TOKEN = 'uat-synthetic-token'
$env:MAIL_MAILER = 'array'; $env:QUEUE_CONNECTION = 'sync'; $env:PHP_CLI_SERVER_WORKERS = '4'
Start-Process $php -ArgumentList '-S','127.0.0.1:8010',"$root\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php" -WorkingDirectory "$root\public"
Start-Process $php -ArgumentList '-S','127.0.0.1:8011',"$root\tests\Uat\fake-frc-api.php" -WorkingDirectory $root
```

Set the variables in that PowerShell session only; the configuration must not be cached (`bootstrap/cache/config.php` absent).

## 3. Run the browser tests

They use the installed Chrome (or Edge) headless with a temporary profile. Nothing is installed.

```
node tests/Uat/browser/workflows.mjs [baseUrl] [outputDir] [--no-reset]
node tests/Uat/browser/smoke.mjs [baseUrl] [outputDir]
```

Results are written to `workflows-results.json` and `smoke-results.json` in the output directory (default `storage/uat/`, git-ignored), with screenshots in `screens/`. MySQL must be running.

### Release UAT (newer modules)

`release.mjs` runs cases RC-01 … RC-13 (COA import, shared cash ledgers, manual/contra journals, receipts/expenses/petty cash with registers and books, inventory, fixed assets and depreciation, budgets, payroll import and payment, FRC incremental changes, authorization by URL, exports, responsive checks, year-end closing). It continues from the state `workflows.mjs` leaves, so run them in this order:

```
node tests/Uat/browser/workflows.mjs                 # rebuilds the UAT database (setup.php --fresh)
node tests/Uat/browser/release.mjs [baseUrl] [outputDir] [--reset]
node tests/Uat/browser/smoke.mjs
```

`release.mjs` does not reset the database unless `--reset` is passed (then it also runs `setup.php --fresh`, but the cases expect the workflows data first). It generates its synthetic upload workbooks with `php tests/Uat/make-fixtures.php` into `storage/uat/fixtures/`, temporarily writes `storage/uat/fake-frc-extra.json` (a new unmapped-service payment and a refund at the synthetic FRC API) and removes it again, and approves the depreciation and payroll policies as the synthetic UAT Director inside the UAT database. RC-13 posts the FY 2026 year-end closing (it zeroes the year's income and expenditure) and creates FY 2027, so rerun `workflows.mjs` before another release run. Results: `release-results.json`, screenshots `screens/rc-*.png`.

## 4. Clean up

Stop both `php -S` processes. The UAT database may be dropped at any time; it holds only synthetic data.
