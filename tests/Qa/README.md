# MySQL QA runners

Two runners exercise the application on real MySQL/InnoDB:

| Runner | Database | What it does |
|---|---|---|
| `tests/Qa/run-mysql-pest.php` | `frc_acct_qa_mysql_test` | Pest feature tests on MySQL, including the exact `DECIMAL(19,4)` storage test in `FinancialAmountLimitTest` |
| `tests/Concurrency/run.php` | `frc_acct_qa_concurrency_test` | Separate PHP processes, each with its own MySQL connection, racing on the same rows |

`tests/MySql/run.php` is disabled: it used the development account.

## Safety model

- Only those two databases are allowed (an exact allowlist in `tests/Qa/QaMysqlGuard.php`). The runners never create or drop a database; the DBA does.
- Credentials come only from `.env.qa-mysql`, which must be gitignored. The development `.env` credentials are never used; its account and database name are only read so they can be refused.
- Before any migration, seeding or wipe the guard checks, on the live connection: driver is MySQL, the database is the allowlisted one, `CURRENT_USER()` is the QA account, `SHOW GRANTS` lists only privilege-free `USAGE` and exact (escaped) grants on the two databases, there is no active role, and `information_schema` shows no other database (in particular not `frc_accounting_software`). Anything missing or unexpected stops the run.
- `migrate:fresh` (concurrency) and `RefreshDatabase` (Pest) delete every table of their database, so both runners require `--confirm-wipe=<database name>`.
- `Tests\TestCase` accepts MySQL only for `frc_acct_qa_mysql_test` inside a QA run, re-verifies the account in every test process, and fails a QA run that is not on MySQL instead of letting it fall back to SQLite.
- Passwords are never printed.

Prefer a separate MySQL instance for QA if one is available; the restricted account below is the minimum.

## 1. DBA setup (once)

The setup file keeps a placeholder password on disk. The commands below create a strong random password in memory, substitute it into the SQL in memory and send it to MySQL through standard input, so the password never lands in a file you could commit, in the command line, or in PowerShell history. The administrator password is typed at MySQL's own prompt.

```powershell
Set-Location E:\wamp64\www\frc-accounting-software
$mysql = 'E:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe'   # MySQL 9.1.0 on port 3306 (MariaDB is 3307: do not use it)
$php   = 'E:\wamp64\bin\php\php8.3.14\php.exe'

# 0. Preflight (read-only): both lines must return no rows. If anything exists, stop.
& $mysql -h 127.0.0.1 -P 3306 -u root -p -e "SHOW DATABASES LIKE 'frc\_acct\_qa\_%'; SELECT user, host FROM mysql.user WHERE user = 'frc_acct_qa';"

# 1. Strong password, in memory only (hex: safe in SQL and in .env)
$bytes = New-Object byte[] 24
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$qaPassword = -join ($bytes | ForEach-Object { $_.ToString('x2') })

# 2. Run the reviewed setup with the password substituted in memory. MySQL stops at the first error.
$sql = Get-Content tests\Qa\dba-setup.sql -Raw
if ([regex]::Matches($sql, 'REPLACE_WITH_A_STRONG_UNIQUE_PASSWORD').Count -ne 1) { throw 'The setup file is not the reviewed version.' }
$sql.Replace('REPLACE_WITH_A_STRONG_UNIQUE_PASSWORD', $qaPassword) | & $mysql -h 127.0.0.1 -P 3306 -u root -p
if ($LASTEXITCODE -ne 0) { throw 'Setup failed; nothing further should be run.' }

# 3. Write .env.qa-mysql (UTF-8 without BOM) with only DB_PASSWORD filled in, then forget the password
$lines = (Get-Content .env.qa-mysql.example) -replace '^DB_PASSWORD=.*$', ('DB_PASSWORD=' + $qaPassword)
[System.IO.File]::WriteAllLines((Join-Path (Get-Location) '.env.qa-mysql'), $lines, (New-Object System.Text.UTF8Encoding($false)))
Remove-Variable qaPassword, sql, bytes, lines
git check-ignore -v .env.qa-mysql      # must print the .gitignore rule
git status --short --ignored .env.qa-mysql   # must show: !! .env.qa-mysql
```

## 2. Verify the QA account (read-only)

Automated, positive and negative:

```powershell
& $php tests\Qa\verify-qa-account.php
```

It connects to both QA databases and runs every guard check, then tries to open and read `frc_accounting_software`, `frc_accounting_software_uat`, `frc_software_vs` and `mysql` and requires each attempt to be refused. Not seeing a database is not accepted as proof on its own.

Independent manual check (type the QA password from `.env.qa-mysql` at the prompt):

```powershell
& $mysql -h 127.0.0.1 -P 3306 -u frc_acct_qa -p -e "SELECT CURRENT_USER(), CURRENT_ROLE(); SHOW GRANTS; SHOW DATABASES;"
& $mysql -h 127.0.0.1 -P 3306 -u frc_acct_qa -p -e "USE frc_accounting_software;"                        # must fail: ERROR 1044
& $mysql -h 127.0.0.1 -P 3306 -u frc_acct_qa -p -e "SELECT COUNT(*) FROM frc_accounting_software.users;"   # must fail: ERROR 1142 or 1044
& $mysql -h 127.0.0.1 -P 3306 -u frc_acct_qa -p -D frc_acct_qa_mysql_test -e "SELECT DATABASE(); SHOW TABLES;"
& $mysql -h 127.0.0.1 -P 3306 -u frc_acct_qa -p -D frc_acct_qa_concurrency_test -e "SELECT DATABASE(); SHOW TABLES;"
```

Expected: `frc_acct_qa@127.0.0.1`, role `NONE`, grants `USAGE ON *.*` plus the two escaped database grants, databases `information_schema`, `performance_schema` (if shown) and the two QA databases only.

## 3. Run (only after explicit approval)

```powershell
# Exact-storage and Phase 4 feature tests on MySQL (wipes frc_acct_qa_mysql_test tables)
E:\wamp64\bin\php\php8.3.14\php.exe tests\Qa\run-mysql-pest.php --confirm-wipe=frc_acct_qa_mysql_test

# Concurrency harness (wipes and rebuilds frc_acct_qa_concurrency_test tables)
E:\wamp64\bin\php\php8.3.14\php.exe tests\Concurrency\run.php --confirm-wipe=frc_acct_qa_concurrency_test --rounds=5
```

Run them one after the other, not at the same time.

## 4. Cleanup

```powershell
& $mysql -h 127.0.0.1 -P 3306 -u root -p -e "source E:/wamp64/www/frc-accounting-software/tests/Qa/dba-cleanup.sql"
Remove-Item .env.qa-mysql
```

## Browser UAT on frc_acct_qa_mysql_test (local only)

Run from the project root in a clean shell (no `DB_*`, `APP_*`, `MAIL_*`, `FRC_*` or similar variables set).
The development `.env` is never loaded; everything comes from `.env.qa-mysql` and `tests/Qa/BrowserUatEnvironment.php`.

1. Build the synthetic fixtures (wipes `frc_acct_qa_mysql_test` only):

   ```
   php tests/Qa/browser-uat-fixtures.php --confirm-wipe=frc_acct_qa_mysql_test
   ```

   The QA logins are written to `storage/qa-browser-uat/qa-credentials.txt` (gitignored, replaced on every run).
   Passwords are never printed. Open the file yourself; do not paste or commit it.

2. Start the server (never `php artisan serve`):

   ```
   php -d upload_tmp_dir=storage/qa-browser-uat/tmp -d sys_temp_dir=storage/qa-browser-uat/tmp -d log_errors=1 -d display_errors=0 -d error_log=storage/qa-browser-uat/logs/php-errors.log -S 127.0.0.1:8020 -t public tests/Qa/browser-uat-server.php
   ```

   Open http://127.0.0.1:8020 in a private window. Every page shows a red QA TEST ENVIRONMENT bar.
   Restart the server after re-running the fixtures (the QA key changes).

3. Clean up: stop the server, then delete `storage/qa-browser-uat/` except its `.gitignore`.
