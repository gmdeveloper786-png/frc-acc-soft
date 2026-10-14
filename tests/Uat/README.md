# Isolated UAT

Everything here runs against a separate database named `<DB_DATABASE>_uat` and a synthetic FRC API. The scripts refuse any database name without the `_uat` suffix. No real data is created or read.

## 1. Build the UAT database

```
E:\wamp64\bin\php\php8.3.14\php.exe tests/Uat/setup.php --fresh
```

This creates synthetic branches, services, users (`uat.{admin,accountant,cashier,approver,director,board,auditor,branchb}@uat.test`, password `Uat-Password-2026!`), a synthetic UAT Director, a synthetic Chart of Accounts named "UAT …", mappings and an open FY 2026. `workflows.mjs` runs this step itself unless `--no-reset` is passed.

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

## 4. Clean up

Stop both `php -S` processes. The UAT database may be dropped at any time; it holds only synthetic data.
