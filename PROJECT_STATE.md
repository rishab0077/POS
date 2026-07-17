# Restaurant POS — Living Project State

Last updated: 2026-07-18

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
- Production images exclude Node.js and npm.
- `npm ci` and the production frontend build complete with zero reported npm vulnerabilities.
- Production uses the database queue; the test profile uses the synchronous queue.
- Automated verification currently passes: **124 tests, 646 assertions**.

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

## Current readiness

The core POS and the local CBMS sales-bill and partial/full credit-note outboxes are working and covered by automated tests. Internal receivable reversal, payment-refund records, net reporting, and waste-by-default returned-item handling are implemented. The application is **not yet approved for live IRD CBMS use** because no taxpayer CBMS credentials or IRD test environment credentials have been supplied, and no live acceptance test has been performed.

CBMS remains off with `CBMS_ENABLED=false`. This allows normal local use and records finalized invoices as pending submissions without contacting IRD.

## Required before enabling live CBMS

1. Enter the restaurant's real legal name, address, and nine-digit PAN/VAT registration in the restaurant settings or production environment.
2. Obtain the taxpayer CBMS username and password from IRD and place them only in the deployment `.env`.
3. Confirm with IRD that the mapped fiscal-year format and numeric invoice sequence are accepted for this installation.
4. Run the database migrations and deploy the rebuilt app, queue, and scheduler images.
5. Test a controlled invoice against IRD, verify response `200`, and confirm it in the CBMS External Portal Sales Register Sync report.
6. Set `CBMS_ENABLED=true` only after the controlled test is approved.

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
