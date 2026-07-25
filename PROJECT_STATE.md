# Restaurant POS — Living Project State

Last updated: 2026-07-25

This file is the shared handoff for the project. Every agent or chat that changes the repository must update it in the same task, as required by `AGENTS.md`.

## Product scope

- Generic product name: **Restaurant POS**.
- Deployment model: one separately deployed POS installation per restaurant.
- The codebase is no longer branded for or coupled to its legacy client deployment.
- Current tax profile: the first restaurant is VAT registered and all menu items use 13% VAT.
- Offline sales must continue; IRD CBMS delivery is asynchronous and retryable.

## Current technical state

- Backend: PHP 8.2 and Laravel 12.
- Database: MySQL 8.
- Frontend: Vite 8, Tailwind CSS 3, Alpine.js, Axios, Chart.js, Laravel Echo, Flatpickr, Pusher JS, and SortableJS.
- Runtime: Docker Compose services for PHP-FPM, Nginx, MySQL, Soketi, the database queue worker, and the scheduler.
- Production images exclude Node.js and npm and include the MariaDB backup client and MySQL 8 authentication connector required by `backup:database`.
- `npm ci` and the production frontend build complete with zero reported npm vulnerabilities.
- Production uses the database queue; the test profile uses the synchronous queue.
- Automated verification currently passes: **130 tests, 684 assertions**.

## Completed work

### Generic product conversion

- Removed legacy client product coupling and changed user-facing defaults to Restaurant POS.
- Kept restaurant identity configurable per installation through database settings and environment bootstrap values.
- Removed obsolete documentation so new documentation can be built from the current generic product.

### Frontend modernization

- Replaced the legacy Laravel Mix/Webpack build with Vite.
- Updated the locked frontend dependency tree and removed the legacy advisory source.
- Preserved production asset building and Docker multi-stage runtime isolation.

### Existing operational and security baseline

- Database-backed sessions and queue infrastructure.
- MFA, recovery codes, recent-authentication checks, account controls, and login rate limiting.
- Audit events with sensitive-value redaction.
- Configurable host separation, private CIDR restrictions, security headers, HSTS, CSP report-only mode, and service authentication.
- Print-station job lifecycle, idempotent acknowledgements, retry controls, and operational status reporting.
- Backup, retention, health, and monitoring commands.
- Inventory deductions, credit payments, table transfers, reporting integrity, and finalized-bill protections.

### IRD compliance — Part 1: fiscal invoice integrity

- Finalizing a bill now creates an immutable fiscal invoice snapshot and immutable line-item snapshots inside the same database transaction.
- Snapshots preserve seller identity, buyer details, operator, payment method, fiscal year, invoice time, item name, quantity, unit price, discounts, taxable sales, exempt sales, VAT rate, VAT, and total sales.
- Every snapshot receives a SHA-256 document hash.
- Finalized receipts and reprints read historical values from the snapshot, so later menu or restaurant-setting changes cannot rewrite an issued invoice.
- VAT calculation now uses integer cents and reconciles taxable value, VAT, and total exactly.
- The shared Nepali date converter was corrected to calculate positive days from the BS year start.

Key files:

- `database/migrations/2026_07_17_000001_create_fiscal_invoice_snapshots.php`
- `app/Models/FiscalInvoiceSnapshot.php`
- `app/Models/FiscalInvoiceItem.php`
- `app/Helpers/BillHelper.php`
- `app/Services/VatCalculatorService.php`
- `app/Services/NepaliDateService.php`
- `app/Services/BusinessConfigurationService.php`
- `app/Helpers/Printers/BillPrinter.php`
- `resources/views/bill/receipt.blade.php`
- `tests/Feature/FiscalInvoiceIntegrityTest.php`

### IRD compliance — Part 2: CBMS sales-bill delivery foundation

- Added one durable CBMS submission record per fiscal snapshot.
- Stored CBMS payloads never contain the taxpayer username or password; credentials are read only from environment configuration at request time.
- Maps the printed fiscal year `082/83` to the separate CBMS value `2082.083`.
- Maps the printed invoice `082/83-0001` to the separate CBMS invoice number `1`.
- Sends the official sales-bill fields to `POST /api/bill` using Laravel's built-in HTTP client.
- Validates seller and optional buyer PAN values as exactly nine digits before transmission.
- Treats IRD response `200` as accepted and `101` as an idempotent already-existing success.
- Records attempts, response codes, responses, errors, last-attempt time, and successful submission time.
- Network and IRD errors leave the invoice queued; the unique queue job retries indefinitely with increasing delays.
- The scheduler runs `cbms:dispatch` every minute when CBMS is enabled, recovering any non-submitted records.
- Live CBMS is disabled by default. No request was made to IRD during development or tests.
- Implementation follows the IRD [CBMS API Technical Document](https://www.ird.gov.np/public/pdf/976029276.pdf).

Key files:

- `database/migrations/2026_07_17_000002_create_cbms_submissions.php`
- `app/Models/CbmsSubmission.php`
- `app/Services/CbmsService.php`
- `app/Jobs/SubmitCbmsInvoice.php`
- `routes/console.php`
- `app/Console/Kernel.php`
- `config/services.php`
- `.env.example`
- `.env.production.example`
- `tests/Feature/CbmsSubmissionTest.php`

### IRD compliance — Part 3: CBMS operations dashboard

- Added an administrator CBMS dashboard with pending, submitting, failed, and submitted counts.
- Added status filtering and invoice, buyer, or PAN search without exposing taxpayer credentials.
- Shows invoice time, buyer, total, attempt count, IRD response code, last error, and last activity.
- Failed submissions can be manually queued for immediate retry only when CBMS is enabled and configured.
- Manual retry requires recent password/MFA confirmation and creates a redacted audit event.
- Automatic and manual jobs use a shared per-submission lock; IRD response `101` remains the final idempotency safeguard.

Key files:

- `app/Http/Controllers/Admin/CbmsSubmissionController.php`
- `resources/views/admin/cbms/index.blade.php`
- `resources/views/layouts/master.blade.php`
- `routes/web.php`
- `app/Jobs/SubmitCbmsInvoice.php`
- `tests/Feature/CbmsDashboardTest.php`

### IRD compliance — Part 4: credit-note foundation

- Added immutable fiscal credit notes with a separate fiscal-year sequence, issue time, required reason, refund method, operator, return totals, and SHA-256 document hash.
- Credit notes never alter or delete the original fiscal invoice snapshot.
- Added the official IRD sales-return payload and delivery to `POST /api/billreturn`; stored payloads exclude the taxpayer username and password.
- A return can be issued only after the original sales bill has CBMS status `submitted`.
- IRD return response `200` is accepted. Unlike sales-bill submission, response `101` is treated as a failure because it is not a successful bill-return code.
- Added durable retry state, an indefinitely retrying unique queue job, scheduler recovery, per-credit-note locking, and administrator manual retry.
- Added a step-up-protected dashboard workflow for returns and a dashboard list of credit-note status, attempts, IRD response, and errors.
- Cooked-item ingredient inventory is deliberately not restored automatically.
- The selected refund method is an auditable operational record; this does not initiate a payment-gateway refund.
- Added an administrator-accessible 80 mm customer credit-note document using the immutable seller, buyer, item, tax, and total snapshots. It can be printed directly from the CBMS dashboard.
- The daily payment summary now records returns on their credit-note issue date and shows gross collections, returns, net collections, gross invoice sales, net sales, returned VAT, and net VAT by refund method.

Key files:

- `database/migrations/2026_07_17_000003_create_fiscal_credit_notes.php`
- `app/Models/FiscalCreditNote.php`
- `app/Jobs/SubmitCbmsCreditNote.php`
- `app/Services/CbmsService.php`
- `app/Services/NepalFiscalYearService.php`
- `app/Http/Controllers/Admin/CbmsSubmissionController.php`
- `resources/views/admin/cbms/index.blade.php`
- `resources/views/admin/cbms/credit-note.blade.php`
- `app/Http/Service/ReportingService.php`
- `resources/views/admin/analytics/daily-summary.blade.php`
- `routes/web.php`
- `routes/console.php`
- `tests/Feature/CbmsCreditNoteTest.php`

### IRD compliance — Phase 5: partial returns and return accounting

- Supports multiple partial item returns against one submitted fiscal invoice while preventing cumulative returned quantities from exceeding the immutable original quantities.
- Each credit note stores its own immutable returned line items, including category, quantity, unit price, line total, tax category, VAT rate, and source fiscal item.
- Uses cumulative proportional allocation so taxable sales, exempt sales, VAT, and total reconcile exactly to the original invoice when the final remaining quantities are returned.
- Supports credit-sale returns. The return first reverses the outstanding receivable; only an amount beyond the remaining receivable is recorded as a payment refund.
- Added immutable internal return transactions for receivable reversals and payment refunds, including method, optional reference, operator, and time.
- Updates credit balances and settlement state after returns without altering the original invoice or payment history.
- The dashboard now selects per-item return quantities, displays prior return documents, prevents over-return, and shows the accounting split.
- The 80 mm credit note prints only the items and quantities on that specific return plus its receivable-reversal/payment-refund breakdown.
- Daily collections deduct payment refunds only; receivable reversals reduce credit balances but do not falsely reduce collected cash.
- Item and category sales reports now subtract immutable return lines issued in the selected period. Historical credit reports use the return state that existed at the selected end date.
- Future fiscal line snapshots preserve category attribution. The current menu model can associate multiple categories with a menu item, so the deterministic first ranked category is stored for reporting.
- Cooked-item ingredient inventory is still not restored automatically.

Key files:

- `database/migrations/2026_07_18_000001_complete_credit_note_accounting.php`
- `app/Models/FiscalCreditNoteItem.php`
- `app/Models/RefundTransaction.php`
- `app/Models/Bill.php`
- `app/Services/CbmsService.php`
- `app/Http/Controllers/Admin/CbmsSubmissionController.php`
- `app/Http/Controllers/Billing/CreditController.php`
- `app/Http/Service/ReportingService.php`
- `resources/views/admin/cbms/index.blade.php`
- `resources/views/admin/cbms/credit-note.blade.php`
- `resources/views/admin/analytics/credits.blade.php`
- `resources/views/admin/analytics/daily-summary.blade.php`
- `tests/Feature/CbmsCreditNoteTest.php`
- `tests/Feature/ReportingIntegrityTest.php`

### IRD compliance — Phase 6: returned-item inventory handling

- Finalization now snapshots the exact automatic ingredient deductions made for each immutable fiscal invoice item.
- Each partial or full return receives a proportional potential-restoration snapshot; final partial allocations reconcile to the original deduction quantity.
- Every returned item defaults to waste, so issuing a credit note never increases stock automatically.
- Admin, owner, or stock-manager users can explicitly restore a genuinely reusable returned item from the inventory stock-movement page.
- Restoration locks the returned line and affected stock items, can happen only once, creates normal inbound stock movements with running balances, and writes an audit event.
- Returns from invoices issued before deduction snapshotting remain waste because their historical ingredient usage cannot be reconstructed safely.

Key files:

- `database/migrations/2026_07_18_000002_add_return_inventory_handling.php`
- `app/Modules/Inventory/Services/StockDeductionService.php`
- `app/Modules/Inventory/Controllers/StockMovementController.php`
- `app/Models/FiscalInvoiceItem.php`
- `app/Models/FiscalCreditNoteItem.php`
- `app/Services/CbmsService.php`
- `resources/views/modules/inventory/stock_movements/index.blade.php`
- `routes/inventory.php`
- `tests/Feature/ReturnedInventoryTest.php`

### IRD compliance — Phase 7A: production readiness

- Added a read-only `php artisan cbms:preflight` command that fails closed when live-acceptance prerequisites are missing.
- The preflight validates the legal restaurant name and address, nine-digit seller PAN, official HTTPS CBMS endpoint, credential presence, asynchronous queue, current migrations, scheduler heartbeat, application/database clock agreement, CBMS outbox availability, zero unresolved pre-activation records, Nepal timezone, and disabled debug mode.
- The command reports whether CBMS delivery is enabled but never contacts IRD or exposes credential values.
- The scheduler now writes a shared heartbeat every minute. Operations status reports heartbeat freshness, database clock skew, CBMS configuration, outstanding/failed counts, and oldest unresolved age.
- The existing operations alert path now reports stale schedulers, clock disagreement, failed CBMS deliveries, and aged unresolved CBMS submissions without adding a second monitoring system.
- Added configurable scheduler, clock-skew, and CBMS-age thresholds to both environment examples.
- Added a credential-safe deployment, rotation, rollback, and Phase 7B handoff runbook.
- Automated coverage verifies the default failure state, a fully configured pre-activation state, status visibility, and CBMS alert routing.

Key files:

- `routes/console.php`
- `tests/Feature/CbmsPreflightTest.php`
- `app/Console/Kernel.php`
- `app/Services/Operations/SystemStatusService.php`
- `app/Console/Commands/OperationsStatusCommand.php`
- `resources/views/admin/system/status.blade.php`
- `config/operations.php`
- `tests/Feature/OperationsPhaseThreeATest.php`
- `docs/CBMS_GO_LIVE.md`

### IRD compliance — Phase 7B: controlled acceptance tooling

- Added an opt-in acceptance mode that suppresses every automatic CBMS delivery path: invoice and credit-note queue dispatch, stale queued jobs, scheduler recovery, and dashboard retries.
- Added `php artisan cbms:accept {invoice|credit-note} {id} --confirm="SEND TO IRD"` for one synchronous, explicitly confirmed live request.
- The acceptance command fails closed unless acceptance mode is active, credentials are configured, the official IRD host is selected, the target is unresolved, and it is the only unresolved CBMS record across both outboxes.
- A live acceptance requires IRD response `200`; sales response `101` is deliberately not treated as a clean acceptance result.
- Operations status and preflight show acceptance-mode state without exposing credentials.
- The go-live runbook now covers one controlled invoice, one controlled return, and manual verification in the IRD Sales Register Sync report before any automatic delivery is enabled.
- All HTTP interactions in automated tests are mocked. No live IRD request was made during implementation or verification.

Key files:

- `config/services.php`
- `app/Services/CbmsService.php`
- `app/Jobs/SubmitCbmsInvoice.php`
- `app/Jobs/SubmitCbmsCreditNote.php`
- `app/Console/Kernel.php`
- `routes/console.php`
- `app/Http/Controllers/Admin/CbmsSubmissionController.php`
- `app/Services/Operations/SystemStatusService.php`
- `docs/CBMS_GO_LIVE.md`
- `tests/Feature/CbmsAcceptanceTest.php`

### Local staff LAN deployment

- The web port remains bound to localhost by default and can be explicitly exposed to a trusted restaurant LAN with `APP_BIND_ADDRESS=0.0.0.0`.
- Kitchen realtime clients now use the current browser origin at runtime, so one production build works from localhost, a reserved LAN IP, or a future HTTPS hostname.
- Backend broadcasts use the Docker-internal `soketi:6001` service instead of incorrectly routing back through the host.
- The README documents the required fixed server address, host allowlists, HTTP session settings, firewall scope, and staff-phone URL.
- LAN access must remain restricted to a private staff network; the POS port must never be forwarded from the internet.
- The production Compose stack has been built, migrated, seeded, and smoke-tested locally on `127.0.0.1:8097`; all six runtime services started successfully.
- The current ignored deployment `.env` is configured for private-LAN access on port 8097, and the app responds through the server's LAN address. The target PC still needs an Administrator-created Windows Firewall rule before phone access can be confirmed.
- The shared desktop and mobile headers display the database-configured restaurant name as plain branding; this installation is configured as **Amber cafe**.
- The deployment branch excludes remote-session attachments, obsolete self-hosted CI/SSH deployment assets, the tracked environment backup, and the stale root print-station credential file. The required placeholder environment and print-station templates remain available.

Key files:

- `docker-compose.yml`
- `.env.docker.example`
- `.env.production.example`
- `resources/js/kitchen.js`
- `resources/views/layouts/kitchen.blade.php`
- `README.md`
- `tests/Feature/LanDeploymentTest.php`

## Current readiness

The core POS and the local CBMS sales-bill and partial/full credit-note outboxes are working and covered by automated tests. Internal receivable reversal, payment-refund records, net reporting, and waste-by-default returned-item handling are implemented. Phase 7A production-readiness and Phase 7B controlled-acceptance tooling are complete. The production deployment and same-origin kitchen realtime configuration have passed localhost and server-side LAN smoke testing. Phone testing still requires the target PC's Wi-Fi profile and scoped Windows Firewall rule to be configured from Administrator PowerShell. The live Phase 7B acceptance exercise still requires taxpayer credentials, IRD coordination, and operator verification in the IRD portal. The application is **not yet approved for live IRD CBMS use**, and no live acceptance test has been performed.

CBMS remains off with `CBMS_ENABLED=false`. This allows normal local use and records finalized invoices as pending submissions without contacting IRD.

## Required before enabling live CBMS

1. Enter the restaurant's real legal name, address, and nine-digit PAN/VAT registration in the restaurant settings or production environment.
2. Obtain the taxpayer CBMS username and password from IRD and place them only in the deployment `.env`.
3. Confirm with IRD that the mapped fiscal-year format and numeric invoice sequence are accepted for this installation.
4. Run the database migrations, deploy the rebuilt app, queue, and scheduler images, and run `php artisan cbms:preflight` with CBMS still disabled.
5. Enable `CBMS_ENABLED=true` and `CBMS_ACCEPTANCE_MODE=true`, then rerun preflight and resolve every failure.
6. Create exactly one controlled invoice, send it with `cbms:accept`, require response `200`, and confirm it in the CBMS External Portal Sales Register Sync report.
7. Issue exactly one controlled return for that accepted invoice, send it with `cbms:accept`, require response `200`, and confirm the return in the portal.
8. Keep acceptance mode enabled until both records are approved and Phase 7C automatic-delivery activation is authorized.

## Known limits and next compliance work

- Return accounting is internal to the POS. It records receivable reversals and payment refunds but does not call bank, card, wallet, or cash-management provider APIs.
- Returned items default to waste. Reusable stock must be restored explicitly by an inventory-authorized user; old invoices without deduction snapshots cannot be restored automatically.
- Historical invoices issued before category snapshotting may place returned items under `Uncategorized Returns` in the category report.
- Credit-note printing currently uses the authenticated browser print flow, not the unattended print-station queue.
- All current items are snapshotted as standard 13% VAT items. Per-item exempt or mixed-tax categories should be added only when a restaurant requires them.
- The five-minute `isrealtime` cutoff is an implementation assumption because the published IRD API document does not define a cutoff. Confirm it with IRD before live activation.
- Software enlistment/approval and restaurant-specific operational sign-off remain external compliance steps.

## Verification history

- 2026-07-17: fiscal snapshot focused tests — 2 passed, 12 assertions.
- 2026-07-17: CBMS and fiscal focused tests — 4 passed, 26 assertions.
- 2026-07-17: complete regression suite — 116 passed, 576 assertions.
- 2026-07-17: CBMS dashboard, retry, submission, and fiscal focused tests — 6 passed, 39 assertions.
- 2026-07-17: complete regression suite after CBMS dashboard — 118 passed, 589 assertions.
- 2026-07-17: CBMS sales and credit-note focused suite — 7 passed, 46 assertions.
- 2026-07-17: complete regression suite after full-invoice credit notes — 121 passed, 609 assertions.
- 2026-07-17: credit-note print and net-reporting focused suite — 8 passed, 50 assertions.
- 2026-07-17: complete regression suite after credit-note print and net reporting — 122 passed, 618 assertions.
- 2026-07-17: production frontend build succeeded during the final Docker test-image build.
- 2026-07-18: Phase 5 focused partial-return, credit-accounting, and reporting suite — 9 passed, 62 assertions.
- 2026-07-18: complete regression suite after Phase 5 — 123 passed, 630 assertions.
- 2026-07-18: production Vite build succeeded during the Phase 5 Docker image build.
- 2026-07-18: returned-inventory focused suite — 8 passed, 66 assertions.
- 2026-07-18: complete regression suite after returned-inventory handling — 124 passed, 646 assertions.
- 2026-07-18: production Vite build succeeded during the returned-inventory Docker image build.
- 2026-07-18: Phase 6 returned-inventory verification rerun — 1 passed, 16 assertions.
- 2026-07-18: Phase 7 CBMS preflight focused test — 1 passed, 6 assertions.
- 2026-07-18: complete regression suite after starting Phase 7 — 125 passed, 652 assertions.
- 2026-07-18: production Vite build succeeded during the Phase 7 Docker test-image rebuild.
- 2026-07-18: current safe configuration preflight failed closed as intended; legal name, PAN, CBMS credentials, and asynchronous queue remain unconfigured, and CBMS remains disabled.
- 2026-07-18: Phase 7A focused preflight and operations suite — 9 passed, 40 assertions.
- 2026-07-18: Laravel schedule inspection confirmed the one-minute `operations:scheduler-heartbeat`; CBMS dispatch remained absent while CBMS was disabled.
- 2026-07-18: complete regression suite after Phase 7A — 126 passed, 662 assertions, including CBMS credential non-disclosure on the operations dashboard.
- 2026-07-18: production Vite build succeeded during the Phase 7A Docker test-image rebuild.
- 2026-07-18: Phase 7B focused CBMS acceptance and regression suite — 12 passed, 90 assertions; all HTTP calls were mocked.
- 2026-07-18: complete regression suite after Phase 7B tooling — 128 passed, 679 assertions.
- 2026-07-18: production Vite build succeeded during the Phase 7B Docker test-image rebuild.
- 2026-07-25: LAN binding and same-origin realtime focused check — 1 passed, 9 assertions.
- 2026-07-25: complete regression suite after LAN deployment support — 129 passed, 688 assertions.
- 2026-07-25: production Vite build succeeded during the LAN deployment Docker test-image rebuild.
- 2026-07-25: production database backup completed and passed gzip and file-integrity validation before local migration.
- 2026-07-25: production Compose deployment completed all migrations and base seeding; app, Nginx, MySQL, queue, scheduler, and Soketi services started successfully.
- 2026-07-25: localhost smoke checks passed — Nginx and MySQL healthy, `/health` and `/login` returned HTTP 200, and `operations:status` reported zero pending migrations, jobs, or failed jobs.
- 2026-07-25: production backup-client focused suite — 9 passed, 33 assertions.
- 2026-07-25: complete regression suite after the production backup-client fix — 130 passed, 689 assertions.
- 2026-07-25: production and test Docker images, including the Vite frontend build, rebuilt successfully.
- 2026-07-25: current deployment configured to bind port 8097 on all host interfaces; LAN-address `/health` and `/login` checks returned HTTP 200 and the TCP port check passed. External phone access remains unverified because Windows rejected the profile and firewall changes without an Administrator session.
- 2026-07-25: restaurant-branding focused suite — 3 passed, 12 assertions; the running container reported the configured Amber cafe business name.
- 2026-07-25: complete regression suite after restaurant header branding — 131 passed, 690 assertions.
- 2026-07-25: repository cleanup scan confirmed the working tree has no remaining self-hosted registry, runner-token, SSH-deployment, remote-attachment, tracked environment-backup, or stale root print-station-config artifacts; the Git remote is GitHub-only.
- 2026-07-25: affected security suite after repository cleanup — 7 passed, 33 assertions.
- 2026-07-25: complete regression suite after repository cleanup — 130 passed, 684 assertions.

## Change log

### 2026-07-17

- Added immutable fiscal snapshots, snapshot-backed receipts, exact cent-based VAT calculation, and integrity tests.
- Added the durable CBMS sales-bill outbox, official payload mapping, credential isolation, retry job, recovery command, scheduler integration, and mocked HTTP tests.
- Fixed signed BS date conversion exposed by the CBMS contract test.
- Isolated automated test settings from the local demo `.env` for deterministic MFA and administrator tests.
- Added this living project-state file and the repository rule that keeps it current.
- Added the staff-facing CBMS status dashboard, safe filters, step-up-protected manual retry, retry audit event, and per-submission concurrency lock.
- Added immutable full-invoice credit notes, official `/api/billreturn` delivery, durable automatic/manual retries, step-up-protected issuance, audit events, dashboard status, and regression coverage.
- Added the customer-printable immutable credit-note document and return-aware gross/net collection, sales, and VAT values to the daily payment summary.

### 2026-07-18

- Added immutable partial-return item snapshots and multiple credit notes per fiscal invoice.
- Added cumulative proportional tax allocation with exact final reconciliation and over-return protection.
- Added internal receivable-reversal and payment-refund transactions, credit-sale return settlement, and refund references.
- Updated credit-note issuance, dashboard, printing, credit balances, daily summaries, item reports, and category reports for partial/full returns.
- Added focused partial-credit-return and net-reporting regression coverage and verified the complete suite.
- Added sale-time ingredient-consumption snapshots, waste-by-default returns, explicit one-time reusable-stock restoration, inventory movements, audit logging, and regression coverage.
- Confirmed Phase 6 with focused and complete regression verification.
- Started Phase 7 with a fail-closed, no-network CBMS production preflight command and focused regression coverage.
- Completed Phase 7A with migration, scheduler, database-clock, and clean-outbox preflight gates; shared operations status and alerts; environment thresholds; and the CBMS go-live runbook.
- Implemented Phase 7B acceptance mode, automatic-delivery suppression, an exactly-one-record confirmed acceptance command, operations visibility, runbook steps, and mocked safety coverage. Live taxpayer acceptance remains pending external credentials and IRD coordination.

### 2026-07-25

- Added explicit private-LAN binding, runtime same-origin kitchen WebSockets, correct Docker-internal Soketi routing, staff-phone setup documentation, and focused regression coverage.
- Added the MariaDB dump client and MySQL 8 authentication connector to the production image, restoring the pre-migration database-backup workflow and covering the runtime package requirement with a regression test.
- Deployed and smoke-tested the production Compose stack locally on port 8097 after creating and validating a database backup.
- Configured the current ignored deployment environment for LAN access and verified the server-side LAN URL; documented the remaining Administrator-only Windows Firewall prerequisite.
- Replaced the sidebar account-name label and mobile app label with the database-configured restaurant name, rendered as plain text, and configured the local installation as Amber cafe.
- Removed obsolete remote-session attachments, self-hosted CI/runner and SSH deployment files, the tracked environment backup, and the stale credential-bearing root print-station config; added ignore rules to prevent them from being recommitted.
