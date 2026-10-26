# Production Readiness Checklist

**Verdict: READY FOR CONTROLLED UAT. Not production ready.**

The software is built, its automated checks pass and an automated browser UAT on synthetic data passed (`UAT_EXECUTION_REPORT.md`), but the business approvals that switch financial posting on have not been given, the FRC payment endpoints are awaiting approval, and the client's staff have not tested it. Current status: `FINAL_HANDOVER_STATUS.md`. Nothing in this document was deployed, and no production system was touched.

Status key: **Done** (verified in this project), **To do** (an action for deployment), **Blocked** (waiting for a decision or an activity outside development).

## 1. Blockers before go-live

| # | Item | Status | Evidence or owner |
|---|---|---|---|
| 1 | Browser and device testing | Partly done | Chrome UAT on synthetic data passed (`UAT_EXECUTION_REPORT.md`); UAT by client staff, other browsers and real devices still to do (`UAT_TEST_PLAN.md`) |
| 2 | Chart of Accounts approved and imported | Blocked | Client decision 1 in `CLIENT_POLICY_DECISIONS.md` |
| 3 | Director appointed | Blocked | Client decision 2 |
| 4 | Operational ledgers mapped | Blocked | Depends on 2 |
| 5 | Opening balances supplied and posted | Blocked | Client decision 7 |
| 6 | Depreciation policy approved | Blocked | Needed only to post depreciation |
| 7 | Payroll rules and usable payroll figures | Blocked | Needed only to post payroll |
| 8 | Independent security test (penetration test) | Blocked | Not performed. Only automated authorization tests and code review were done |
| 9 | Backup and restore rehearsal | To do | Not rehearsed |
| 10 | Performance test with realistic volumes | To do | Reports were tested for correctness with small fixtures only |
| 11 | FRC payment and enrollment endpoints deployed and token re-issued | To do | Approved and implemented in the FRC software (14 Oct 2026); activation steps in `FRC_FEE_INTEGRATION_DESIGN.md` §2 |
| 12 | Fee policy (cash or accrual) and settlement accounts approved | Blocked | Director setting and client decision |

## 2. Environment configuration

| Item | Required for production | Status |
|---|---|---|
| `APP_ENV` | `production` | To do (`.env.example` ships `local`) |
| `APP_DEBUG` | `false`. With `true`, error pages reveal code, queries and settings | To do (`.env.example` ships `true`) |
| `APP_URL` | The real HTTPS address | To do |
| `APP_KEY` | Generated once per environment, kept secret, backed up. Losing it makes encrypted bank and vendor details unreadable | To do |
| `LOG_LEVEL` | `warning` or `error` | To do (`debug` in the example) |
| `SESSION_SECURE_COOKIE` | `true` (HTTPS only) | To do (not set in the example) |
| `SESSION_ENCRYPT` | `true` recommended | To do (`false` in the example) |
| `SESSION_DRIVER` | `database` | Done. The budget form relies on it: a large budget is flashed to the session after a failed save and would not fit a cookie |
| HTTPS | Enforced by the web server, with HSTS | To do |
| Database user | Dedicated account with rights on this database only; no `root` | To do |
| `FRC_API_TOKEN` | A token issued for the production FRC system, set only in the server environment | To do |
| Config and route cache | `php artisan config:cache route:cache view:cache` after each deploy | To do |
| PHP | 8.3 with `bcmath`, `mbstring`, `pdo_mysql`, `zip`, `gd` or `iconv` | To do (development used 8.3.14) |
| Composer | `composer install --no-dev --optimize-autoloader` | To do |
| Front end | `npm ci && npm run build` on the build machine | Done locally; repeat per release |

## 3. Credentials and secrets

| Item | Status |
|---|---|
| `.env` is excluded from version control | Done (`.gitignore`) |
| No credentials in source, views, logs or documentation | Done (reviewed; audit log strips password, token, secret and key fields) |
| Bank account numbers and vendor and employee bank details are encrypted at rest | Done (encrypted casts) |
| The client payroll workbook in `storage/app/private/references/` holds real personal data | To do: remove it from the server or restrict access; it is not needed at run time |
| First Administrator created with `php artisan app:create-administrator` | To do |
| Director appointed only with `php artisan app:director-authority grant` | To do, after the owner names the Director |

## 4. Files and storage

| Item | Status |
|---|---|
| Uploaded workbooks, bill attachments, asset documents and payroll imports are stored on the private disk, outside the web root | Done |
| Downloads check permission and branch | Done (tested) |
| Reports are streamed and never written to public storage | Done |
| `public/storage` holds only the organisation logo | Done |
| `storage/` and `bootstrap/cache/` writable by the web server only | To do |
| Web root is `public/` | To do |

## 5. Database

| Item | Status |
|---|---|
| All migrations are forward-only and were applied to MySQL 9.1 without error | Done |
| No migration drops or rewrites data | Done |
| Foreign keys restrict deletion of financial references | Done |
| Unique constraints for voucher numbers, journal sources, purchase order numbers, depreciation per asset and period, payroll per branch and period | Done (verified on MySQL) |
| Concurrency on MySQL/InnoDB | Done: 23 checks with 8 parallel processes (see the QA report) |
| Transaction isolation | `REPEATABLE READ` was tested. Keep the server default |
| Deployment order | Back up, enable maintenance mode, `php artisan migrate --force`, clear and rebuild caches, disable maintenance mode |
| Never run `migrate:fresh`, `migrate:refresh`, `db:wipe` or uncontrolled seeders in production | Rule |

## 6. Backup, recovery and rollback

| Item | Status |
|---|---|
| Nightly full database backup, plus binary logs for point-in-time recovery | To do |
| Backups encrypted and stored off the server | To do |
| Backup of `storage/app/private` (attachments and imports) | To do |
| Secure copy of `APP_KEY` | To do |
| Restore rehearsed on a separate server, with a Trial Balance compared before and after | To do |
| Rollback of a release | Restore the previous code and, if a migration ran, restore the pre-deployment backup. Migrations have `down()` methods, but restoring the backup is the supported path once financial data exists |
| Correcting financial data | Only by reversal entries in the application. Never edit journal tables by hand |

### Local backup taken before the FRC integration activation (development only)

Taken on 14 October 2026, before the Laravel 10 index migration, the integration token rotation and the first real sync, in `E:\wamp64\backups\frc_activation_20261014_135432\`: a dump of each local database and a copy of the Accounting `.env`. Completeness was verified (dump trailer present; payment and enrollment rows included).

| Item | Status |
|---|---|
| Location outside the web root, not served by any web server alias | Done (verified) |
| Access restricted to the owner's Windows account, Administrators and SYSTEM; inherited `Users` and `Authenticated Users` access removed on this folder and its files only (parent folders unchanged) | Done (14 Oct 2026, verified with `icacls`; file contents verified unchanged by SHA-256) |
| Sensitivity | The `.env` copy holds the application key, the database password and the previous (revoked) integration token; the dumps hold FRC operational data. Treat the folder as secret: do not copy it to shared drives, cloud sync folders or email |
| Encryption at rest | To do: the files are plain text; BitLocker on the drive or an encrypted archive is recommended |
| Retention | To do: decide how long to keep it; delete it securely once a newer verified backup exists |
| Parent folder `E:\wamp64\backups\` | Still inherits broad local access; any new backup created there must be restricted the same way |

## 7. Security controls in place

| Control | Status |
|---|---|
| Login throttling, inactive users refused, no public registration | Done (tested) |
| CSRF protection on every form | Done (framework default; no exemptions) |
| Default-deny permissions; branch scoping on the server | Done (tested, including changed-URL access) |
| Maker-checker on journals, payments, bills, purchase orders, budgets, depreciation, payroll, stock adjustments | Done (tested) |
| Director authority for amounts above the threshold, for threshold changes and for bills above their purchase order | Done (tested) |
| Administrator cannot grant Director authority or take over a Director account | Done (tested) |
| Manual journals cannot pay money out of cash or bank | Done (tested) |
| Posted journals, audit records and workflow histories are immutable | Done (tested) |
| Spreadsheet formula injection neutralised in every export | Done (tested) |
| Payroll amounts hidden without the sensitive permission, on screen, in exports and in the audit viewer | Done (tested) |
| Sign-in, sign-out and failed sign-in are audited | Done |
| 21 authenticated routes have no permission middleware (finance transactions, purchase bill actions, profile, sign-out, books) | Accepted: their permission depends on the transaction type and is enforced in the controller and service (tested) |
| Independent penetration test | Not performed |
| Two-factor authentication | Not implemented |
| Password policy | Framework default rules; confirm they meet FRC policy |

## 8. Integration with the FRC Management Software

| Item | Status |
|---|---|
| Read-only, token-authenticated, limited to four read abilities (branches, services, enrollments, payments) | Done (enforced on the FRC side by three layers). Locally the token was re-issued on 14 Oct 2026 with these four abilities; it expires on 12 Jan 2027 and must be rotated before then |
| Separate databases; no direct writes | Done |
| Timeouts and limited retries; token never shown in errors | Done |
| Fee, enrollment and payment synchronisation | Built; FRC endpoints approved and implemented. Locally activated on 14 Oct 2026 (mirror only, no postings). Not deployed to production |

## 9. Logging and monitoring

| Item | Status |
|---|---|
| Application errors logged to `storage/logs` | Done |
| Log rotation and retention | To do (use the `daily` channel with a retention period) |
| Alerting on errors and failed sign-ins | To do |
| Financial audit log with viewer | Done |
| Audit log retention | To do: decide the period; records are never deleted by the application |
| Uptime monitoring using `/up` | To do |

## 10. Queues and scheduling

The application defines no queued jobs and no scheduled tasks, so no queue worker or scheduler is required today.

## 11. Performance and data volume

| Item | Status |
|---|---|
| Reports aggregate in SQL and paginate long lists | Done |
| Indexes on journal date, branch, status, account and service | Done |
| Exports build the whole file in memory | Limitation: a General Ledger export of tens of thousands of lines will need more PHP memory. Set `memory_limit` to at least 512M and test with real volumes |
| Budget form | Tested with 250 ledgers and 12 periods (3,000 lines) |
| Load test with realistic volumes | To do |

## 12. Known limitations to accept or schedule

- PDF exports use a simple built-in layout (Helvetica, A4 landscape, text only). A PDF library would give richer layout but adds a dependency that needs approval.
- Asset disposal, payslips, tax calculation and bank reconciliation are not built. Fee synchronisation is built and activated locally (see `FRC_FEE_INTEGRATION_DESIGN.md`).
- Branch balance sheets are labelled incomplete when a branch's entries do not balance on their own.
- Service reports show direct income and expenses only.

## 13. Release steps (when the blockers are cleared)

1. Complete UAT and sign it off.
2. Take and verify a backup.
3. Deploy code; run `composer install --no-dev`, `php artisan migrate --force`, cache commands.
4. Create the Administrator; synchronise branches and services.
5. Import the approved Chart of Accounts; set GL mappings; open the fiscal year and period.
6. Appoint the Director from the console.
7. Enter and post opening balances; compare the Trial Balance with the client's figures.
8. Confirm the Setup Readiness screen shows every check as ready.
