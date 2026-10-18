# Restaurant POS — Living Project State

Last updated: 2026-10-18

This file is the shared handoff for the project. Every agent or chat that implements a material repository change must update it in the same task, as required by `AGENTS.md`; plans must remain clearly separated from completed work.

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
- Latest recorded PHP verification (2026-10-15): **168 tests, 960 assertions**. Three JavaScript regressions and the production frontend build passed again on 2026-10-18. Sanjay's restaurant server now runs cancellation release `82910ee`; HTTP health and print-agent heartbeats pass, while staff workflow and physical-print acceptance remain pending.

## Completed work

### 2026-10-18 — Cancellation release deployed to Sanjay's LAN server

Published application release `82910ee098b72212d91a687c5e72a26ecdac9e19` to `local-lan-deployment`. Operator screenshots confirm a fast-forward in `C:\RestaurantPOS` from `f967a0d`, with hashes unchanged for `.env`, `.dockerignore`, the customized print agent, local printer configuration, and `.git/info/exclude`. Local Docker/Git credential exclusions remain present.

Built separately tagged `restaurant-pos-release-app:82910ee` and `restaurant-pos-release-web:82910ee` images. Before service changes, saved `rollback-compose.yml` and syntax-checked `rollback.ps1` in `C:\RestaurantPOS-local-backup-20261018-200949` and confirmed both rollback images exist. Maintenance was enabled; Nginx, queue, and scheduler were stopped. A fresh `database-cutover.sql.gz` backup (24,874 bytes) passed gzip integrity and copied-file SHA-256 checks. Restore validation and rollback execution were not performed.

Cleared caches with the release image, checked migrations (displayed entries all Ran; no new migration in this release), and recreated app, Nginx, queue, and scheduler with the explicit release override. MySQL/Soketi and database/storage volumes were preserved. Maintenance was removed. Operator output confirms `/health` returned `status: ok`, MySQL/Nginx healthy, and the recreated services running on release images. Logs confirm repeated HTTP 200 responses from `/api/print-station/heartbeat`; generic PHP-FPM requests alone were not treated as printer evidence. CBMS-disabled guards preceded worker startup and no live IRD submission was invoked.

Future service changes must include `-p restaurant-pos -f docker-compose.yml -f C:\RestaurantPOS-local-backup-20261018-200949\release-compose.yml`; the base file alone still selects the previous default image tags. Recovery is the saved `rollback.ps1`, which recreates the four services from saved images, clears caches, and leaves maintenance; it does not restore the database or rewind the checkout. Do not remove the protected backup directory or image tags. The separate `restaurant-pos-split-preview` deployment remains untouched.

Pending operator acceptance: physical KOT output, waiter cancellation with required reason/actor audit, unpaid-preview cleanup and table reuse, remaining-ticket previews, and stale-button rejection on cancelled tickets. Printed/in-flight tickets require kitchen notification; final invoices require returns; prepared-food wastage remains manual. Backup restore is unverified. Six unrelated audit findings remain open.

### 2026-10-18 — Cancellation release preparation and server backups (before rollout)

Reviewed the cancellation controllers, shared status guards, printer-job handling, routes, screens, and regression coverage for source publication. Local and remote deployment branches matched at `f967a0d` before publication. Three JavaScript tests, the production Vite build, and diff checks passed again. Docker is not running on the development PC, so PHP tests, PHP syntax, and Blade compilation were not rerun today; their latest recorded successful verification remains 2026-10-15. No migration files changed.

Before rollout, operator screenshots confirmed Sanjay's existing `C:\RestaurantPOS` installation was at `f967a0d`, with all six services running and MySQL/Nginx healthy. Configuration copies in `C:\RestaurantPOS-local-backup-20261018-200949` passed hash comparisons with restricted folder permissions. The database backup passed gzip integrity and copied-file SHA-256 checks (24,871 bytes); restore validation was skipped. The running app/web images received rollback tags `restaurant-pos-rollback-app:20261018-200949` and `restaurant-pos-rollback-web:20261018-200949`, and were exported to a 92,089,856-byte archive. Environment and printer configuration contents were not printed or added to source control.

The rollout steps originally pending at this preparation point are recorded as completed in the deployment entry above. Building alone did not update containers; the four affected services were subsequently recreated. Physical printing, backup restore, and staff acceptance remain unverified. The separate `restaurant-pos-split-preview` deployment is untouched; no live IRD request was performed.

### 2026-10-15 — Staff cancellation after KOT (local verification; deployed 2026-10-18)

Implemented waiter, cashier, manager, administrator/owner, and kitchen cancellation of whole unfinalized tickets in New, Processing, Ready for Pickup, and Served states. Controls are shared by KOT and table-order screens; the waiter order screen links to its table's tickets. The generic cancellation route also works when the kitchen module is disabled. A reason is required, including Customer Cancelled Booking; notes and the cancelling operator are retained in the audit trail. KOT polling pauses while the cancellation form is open so it does not discard the operator's input.

Cancellation recalculates unpaid previews or soft-deletes empty ones, preserves order lines, releases an empty table or keeps remaining paid tickets finalized, suppresses unsent KOT/BOT/summary jobs, and blocks printer retry or expired-lease reclaim after cancellation. Status writes share table/order locks and reject revival of cancelled/closed tickets or closure of unpaid tickets. Final invoices, including legacy soft-deleted final invoices, remain protected and require returns.

Verification completed on 2026-10-15 after Docker recovered: twelve focused cancellation tests passed (84 assertions before the expired-lease assertion was added); the final complete suite passed 168 tests / 960 assertions, including that assertion. Three Node workflow regressions, JavaScript/PHP syntax, production Vite build, Blade compilation/rendered waiter/KOT screens, and diff checks passed. No verification was blocked at that handoff. No new migration is required. The cancellation extension was subsequently deployed to the restaurant on 2026-10-18; isolated-preview deployment remains pending.

Limits: already printed or in-flight tickets cannot be physically recalled; staff must notify the kitchen. Prepared-food wastage uses the existing manual inventory workflow rather than automatic stock restoration/deduction. This is whole-ticket cancellation, not partial item removal. The six other follow-up audit findings remain unfixed; the order-state finding is fixed and tested in the release deployed on 2026-10-18; staff acceptance remains pending.

### 2026-10-13 — LAN reliability repairs (deployed to the restaurant server on 2026-10-14)

The ten audit findings were verified through failing baseline regressions and tracing their callers before implementation. Repairs were completed in phases and pushed as `f967a0d`. Operator screenshots confirm deployment to the existing restaurant LAN server on 2026-10-14; this task did not update the isolated port 8097 preview.

| Finding | Verified cause and implemented correction |
|---|---|
| 1. Settled table cannot accept the next order without refreshing | The click handler captured the initial status. It now reads the current table status after settlement/finalization. |
| 2. Cancellation leaves a table occupied or cancels an invoiced ticket | Cancellation now locks the table and order, rejects finalized tickets, and releases the table only after its last active unpaid ticket is cancelled. |
| 3. Failed checkout leaves an order behind, encouraging duplicates on retry | Order creation, billing, print jobs, and audit records now share a transaction; kitchen broadcasts occur after commit. A failed high-value checkout leaves no order or bill. |
| 4. Settlement can expose an available table before closing its orders | Settlement closes orders and bills atomically before releasing the table. Submission, billing, cancellation, and settlement coordinate on the same table lock. Table-cache invalidation also runs after commit. |
| 5. Simultaneous orders can reuse a KOT number | KOT generation now reserves a daily number under a row lock in the existing sequence table, initialized above the largest existing ticket suffix. |
| 6. Menu deletion destroys order lines; menu edits rewrite sales identity | Referenced menu deletion is rejected. Item/category reports use immutable fiscal item snapshots, with a fallback for older bills without snapshots and the existing bill-date/deletion filters. |
| 7. Stale purchase requests post or void stock twice | Purchase posting and voiding reload and lock the invoice before checking its status or payments. |
| 8. Supplier payments can exceed the invoice balance or pay a draft | Invoice-linked payments lock a posted invoice, recalculate its balance, validate the payment, and save/recalculate within one transaction. |
| 9. Simultaneous returns can refund the same units twice | Credit-note issuance locks the original bill before reading accumulated return quantities. |
| 10. KOT polling misses other operators/status changes or repeatedly reloads | Polling compares all visible pending ticket IDs and statuses, handles an empty screen, and matches the waiter table-only view. |

- Phase 1: table reuse, cancellation, failed checkout, settlement, and polling repaired.
- Phase 2: ticket numbering and historical menu/report integrity repaired.
- Phase 3: purchase, supplier-payment, and return concurrency repaired; independent PHP worker processes reproduce the financial races and verify the fixes.
- Phase 4: complete regression, syntax, asset, Blade, and migration verification passed. Testing also exposed an older billing migration rollback bug: its `down()` dropped the parent first and misspelled `bill_orders`. The child now drops first; the complete isolated migration batch rolls back successfully.

Verification: 156 PHP tests / 875 assertions; 17 focused workflow/payment/report tests / 162 assertions before the final full run; two Node workflow regressions; PHP syntax checks; Docker test-image build including the production Vite build; Blade compilation; complete migration rollback on the disposable `restaurant-pos-automated` database; and `git diff --check`. No live IRD requests or production-data migrations were performed.

Remaining acceptance steps: verify closing and reusing a table, cancellation, KOT updates from two staff devices, and physical receipt printing. The ten repairs are deployed, but these operator checks remain pending. Existing duplicate KOTs or previously deleted history are not rewritten; old bills without snapshots cannot recover missing sale-time names/categories. No new forward migration is required by these repairs.

### 2026-10-14 — Restaurant LAN deployment and follow-up audit

- Evidence is operator-provided screenshots and the user's health-check result, not direct remote access. The existing installation is `C:\RestaurantPOS`, Compose project `restaurant-pos`.
- The database backup completed and gzip/file integrity validation passed. A restore test was explicitly skipped; no restored-copy validation is claimed.
- The operator preserved the existing environment and printer configuration outside the checkout, stashed and restored the local print-agent customization, and added local Docker/Git exclusions for printer credentials and environment backups. No secrets are recorded here.
- Git fast-forwarded from `c8ff84b` to `f967a0d`; application and web images built successfully. App, Nginx, queue, and scheduler were stopped, caches cleared, and all four services recreated and started. MySQL/storage volumes were preserved. The migration command reported `Nothing to migrate`.
- The user confirmed `/health` returned `status: ok`. The final log screenshot shows HTTP 200 print-station heartbeats; this verifies agent communication, not physical receipt output. Final phone, table-workflow, and thermal-printer acceptance remain pending.
- A separate read-only audit reran the existing suite: 156 tests passed with 875 assertions. Temporary probes outside the repository reproduced seven additional bugs; none of those seven were implemented or deployed as fixes.

Open audit findings (planned corrections only): unrestricted order status changes can close unpaid tickets or revive cancelled tickets; stale purchase edits can rewrite posted items; stale stock forms overwrite newer quantities/costs; a refused table deletion deletes reservations; stale failure acknowledgements overwrite completed print jobs; reservation dates remain strings and crash booking/edit flows; and purchase voids leave average-cost valuation incorrect.

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
- The deployment branch excludes remote-session attachments, obsolete self-hosted CI/SSH deployment assets, the tracked environment backup, the stale root print-station credential file, the unused `printPdf.exe`, legacy PHP 7.4/8.0/8.1 Sail runtimes, and superseded host-install scripts. The required placeholder environment and print-station templates remain available.

Key files:

- `docker-compose.yml`
- `.env.docker.example`
- `.env.production.example`
- `resources/js/kitchen.js`
- `resources/views/layouts/kitchen.blade.php`
- `README.md`
- `tests/Feature/LanDeploymentTest.php`

### Physical stamp-card loyalty rewards

- Administrators can mark a menu category as eligible for physical stamp-card rewards; existing categories default to ineligible, and a menu is eligible when any assigned category is eligible.
- The ordering/KOT flow only identifies eligible menu items; it does not redeem cards or change prices. For an existing table bill, redemption can be prepared in the editable unpaid Bill Preview or selected in Final Bill before payment.
- POS cashiers can select one eligible item unit per physically collected completed card through clear **Redeem one** and **Undo one** controls. Existing table orders and newly selected takeaway/table items are supported.
- Printing the unpaid preview persists the pending reward on the editable bill, prints the reward at `NPR 0.00`, and allows it to survive a page reload or another device opening the bill. Proceed to Payment carries the preview selection into Final Bill; selections remain editable until finalization.
- The unpaid Bill Preview lists every ordered menu item with quantity, unit price, and regular line total. Its source selector is hidden for ordinary single-table bills and remains available only when transferred or merged orders create multiple meaningful billing sources.
- Finalization locks the selected rewards and recalculates order, discount, tax, split-payment, bill, and receipt totals in one database transaction. Finalized fiscal bills remain immutable.
- The server independently validates menu eligibility and reward quantities and rejects forged zero-price requests, waiter/order-time rewards, quantities above the ordered quantity, and changes to finalized bills. Invalid selections are also preflighted before a new immediate-bill order is inserted.
- Kitchen and bar tickets retain the full prepared quantity. Inventory deduction also retains the full quantity even though rewarded units produce zero sales revenue.
- Fiscal snapshots split mixed paid/reward quantities into paid lines and explicit `NPR 0.00` loyalty lines while preserving the original unit price, category, approving cashier, approval time, and proportional inventory-consumption snapshot.
- Browser and ESC/POS receipts label the zero-price item as a loyalty reward and show its regular price. Bill details and the discount report expose redemption quantities and promotional value.
- Item and category sales reports count all served quantities but exclude the rewarded units from sales revenue.
- Existing dine-in menu lines are visible wherever an operator acts on an existing bill: the main POS order screen shows read-only existing lines alongside editable new items, the waiter order screen pairs its current-table total with the existing lines, and Final Bill shows the selected billing source's ordered items. Bill Preview, finalized bill detail, and browser/thermal receipts remain itemized; table, bill, and credit index pages intentionally remain compact summaries with detail actions.
- Release verification completed on 2026-09-14: focused itemized unpaid-preview and final-payment loyalty coverage passed with 5 tests and 52 assertions; the complete suite passed with 140 tests and 793 assertions; JavaScript syntax, the Vite production build, Blade rendering through HTTP tests, and `git diff --check` passed.
- Production images for the isolated `restaurant-pos-split-preview` stack were rebuilt with the unpaid-preview flow, all six services were recreated and started, no migrations were pending, MySQL reported healthy, and `/health` returned `{"status":"ok"}`. That preview check predated the restaurant rollout. The operator deployed f967a0d on 2026-10-14 with no pending migrations; cashier, phone, and thermal-printer acceptance remain pending.
- CBMS remains disabled. During controlled acceptance, confirm with the restaurant's tax adviser/IRD that an item retained on the invoice at zero payable price is the accepted treatment for this physical-card promotion before enabling automatic delivery.

Key files:

- `database/migrations/2026_09_04_000001_add_physical_loyalty_rewards.php`
- `app/Http/Controllers/Order/OrderController.php`
- `app/Http/Controllers/POS/PosController.php`
- `app/Helpers/BillHelper.php`
- `resources/views/pos/pos-index.blade.php`
- `resources/views/bill/receipt.blade.php`
- `app/Helpers/Printers/BillPrinter.php`
- `app/Http/Service/ReportingService.php`
- `tests/Feature/LoyaltyRewardTest.php`

### Split tender payments

- Finalized non-credit bills now record immutable payment allocations in `bill_payments`; existing single-method bills keep their summary payment method and receive one allocation, while split bills use the `split` summary value and exactly two allocations.
- Dine-in and immediate takeaway billing accept two distinct non-credit methods, calculate the second amount as the exact remainder, and validate positive amounts and exact cent equality again on the server inside the finalization transaction.
- Selecting split tender initially fills an exact half-and-remainder allocation, includes active table orders in the payable total, and keeps both order-page and final-bill controls compact and vertically scrollable on shorter viewports.
- Browser, PDF, and ESC/POS receipts, administrator bill details, CBMS return screens, audit events, and fiscal snapshots show or preserve the allocation breakdown and optional transaction references.
- Daily payment reporting aggregates each allocation under its actual method and retains a compatibility fallback for historical or manually-created finalized bills without allocation rows.
- Credit cannot be mixed into a split payment, and the POS records but does not initiate or verify card, wallet, transfer, or refund transactions.

### Mobile POS and interface consistency

- The phone POS now stacks menu selection and billing vertically instead of squeezing both desktop columns into the viewport; narrow children are allowed to shrink without widening the page.
- POS navigation remains in a contained horizontal scroller on phones, keeping Tables, Bills, KOT View, and Dashboard reachable without widening the document.
- Table dialogs use flex display, viewport-bounded height, and internal vertical scrolling; obsolete modal positioning rules no longer override the current Tailwind layout.
- The Bills data table keeps its minimum readable width while containing horizontal scrolling inside the card.
- Split method selectors prevent duplicate methods immediately, while server-side distinct-method validation remains authoritative.
- A first split amount equal to or above the bill total remains visible and produces an explicit error instead of silently reverting to a half split.
- Icon-only table actions now expose tooltips and accessible names, including Close Table.
- User-facing currency labels across POS, billing, analytics, cart, and inventory views use `NPR` consistently.
- The Add Notes dialog is viewport-bounded on phones, and each save replaces the in-memory note selection so reopening the dialog cannot duplicate notes.

Key files:

- `database/migrations/2026_09_03_000001_create_bill_payments.php`
- `app/Models/BillPayment.php`
- `app/Helpers/BillHelper.php`
- `app/Http/Service/ReportingService.php`
- `resources/views/pos/pos-index.blade.php`
- `resources/views/pos/tables.blade.php`
- `tests/Feature/SplitPaymentTest.php`

## Current readiness

Staff cancellation after KOT, including waiter access, is implemented and verified locally with 168 passing PHP tests and three JavaScript tests. Source/image rollout to Sanjay's restaurant server completed as `82910ee` on 2026-10-18; HTTP health and printer-agent communication pass, while operator workflow acceptance remains pending. Final-invoice cancellation continues to use returns; physical printer and phone acceptance of this extension remain pending.

The 2026-10-13 LAN reliability repairs passed isolated automated verification and were deployed to the existing restaurant LAN server on 2026-10-14, as confirmed by operator screenshots and the health result. The isolated port 8097 preview was not updated by this task. Phone/browser, table-workflow, and physical-printer acceptance remain pending. The cancellation release deployed on 2026-10-18 resolves the order-state issue; the other six follow-up findings remain open.

The core POS, including exact two-method split tender payments and physical stamp-card loyalty rewards, and the local CBMS sales-bill and partial/full credit-note outboxes are working and covered by automated tests. Internal receivable reversal, payment-refund records, net reporting, and waste-by-default returned-item handling are implemented. Phase 7A production-readiness and Phase 7B controlled-acceptance tooling are complete. The loyalty release has passed an isolated six-service Compose migration and health smoke test. An operator-provided diagnostic from the Amber POS installation confirmed the reserved LAN address, private network profile, scoped firewall rule, six healthy/running Compose services, HTTP health checks, current split-payment migration, scheduler heartbeat, database backup, an online Windows print station, and its logon-triggered agent task. That diagnostic predated the 2026-10-14 rollout; the current server reports no pending migrations, while loyalty operational acceptance remains pending. The live Phase 7B acceptance exercise still requires taxpayer credentials, IRD coordination, and operator verification in the IRD portal. The application is **not yet approved for live IRD CBMS use**, and no live acceptance test has been performed.

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
- Split tender currently supports exactly two distinct non-credit methods. Mixed paid/credit tender, more than two allocations, and payment-provider integrations remain out of scope.

## Verification history

- 2026-10-15: cancellation extension — twelve focused cases passed, then complete suite 168 passed / 960 assertions. Three Node regressions, PHP/JavaScript syntax, production Vite build, Blade compilation and rendered-screen checks, and diff checks passed. Docker startup blockage resolved; no live restaurant deployment or IRD request was performed.
- 2026-10-14: unchanged application suite passed again — 156 tests, 875 assertions; separate temporary audit probes reproduced seven additional unfixed issues. Operator evidence confirms restaurant-server backup integrity, image builds, no pending migrations, recreation/start of four application services, passing HTTP health, and successful print-station heartbeats. Backup restore and staff-device/physical-print acceptance were not performed.
- 2026-10-13: phased LAN reliability repairs — complete suite 156 passed, 875 assertions; focused suite 17 passed, 162 assertions; two JavaScript regressions passed. Independent-worker purchase/payment/return races, PHP syntax, production Vite build, Docker test-image build, Blade compilation, complete isolated migration rollback, and diff checks passed. Live restaurant deployment was not changed.
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
- 2026-07-25: Composer manifest and lock validation passed after removing Laravel Sail; complete regression suite after legacy deployment cleanup — 130 passed, 684 assertions.
- 2026-07-26: production LAN HTTP asset regression — 2 passed, 10 assertions; complete suite — 131 passed, 685 assertions.
- 2026-09-03: documentation-only review verified the clean `local-lan-deployment` branch at revision `791d450`; no runtime tests were rerun because application code was unchanged.
- 2026-09-03: split-payment focused suite — 3 passed, 30 assertions.
- 2026-09-03: complete regression suite after split tender implementation — 134 passed, 715 assertions.
- 2026-09-03: production frontend build completed successfully with `npm run build`.
- 2026-09-03: production Docker images rebuilt successfully; an isolated preview database ran all migrations and seeders, all six services started, every Blade template compiled, and `/health` and `/login` returned HTTP 200 on port 8097.
- 2026-09-03: the older `restaurant-pos_mysql_data` volume was left untouched because its stored database credentials no longer match the current `.env`; the running preview uses the separate `restaurant-pos-split-preview` volumes and is not a migration of that older data.
- 2026-09-03: shared Docker storage ownership regression check — 9 passed, 35 assertions. Runtime inspection confirmed PHP-FPM workers, the queue worker, and the scheduler run as `www-data`; an application-user log write succeeded and `/health` returned HTTP 200.
- 2026-09-03: live browser smoke test retried the previously failing table KOT submission successfully, returned to the table view without a JavaScript error dialog, and preserved the running NPR 140 order on table T2.
- 2026-09-03: split-payment UI, billing hardening, and order-flow regression suite after the usability fixes — 11 passed, 81 assertions; complete regression suite — 135 passed, 727 assertions.
- 2026-09-03: rebuilt the Vite assets and production `app`/`nginx` images, recreated the preview application services, and confirmed all six Compose services running with MySQL and Nginx healthy and no application errors in the final service-log sample.
- 2026-09-03: live browser verification confirmed a NPR 140.00 bill auto-filled as NPR 70.00 plus NPR 70.00, the payment footer exposed its own vertical overflow inside the viewport, the final-bill modal was viewport-bounded and scrollable, and the browser console contained no errors.
- 2026-09-03: mobile POS, modal, Bills overflow, split-input, accessibility, and currency hardening — focused suite 4 passed, 50 assertions; complete regression suite 135 passed, 737 assertions; `node --check resources/js/pos.js`, `git diff --check`, and the production Vite build passed.
- 2026-09-03: the running port 8097 preview was inspected at a phone viewport but still serves the earlier image and does not contain this task's working-tree changes; final visual smoke testing remains required after redeployment.
- 2026-09-03: approved Add Notes mobile overflow and duplicate-selection fixes — focused suite 4 passed, 54 assertions; complete regression suite 135 passed, 741 assertions; JavaScript syntax, production Vite build, and diff checks passed.
- 2026-09-06: corrected loyalty redemption to occur only in Final Bill immediately before payment; focused suite 5 passed, 37 assertions; complete regression suite 140 passed, 778 assertions; JavaScript syntax, production Vite/Blade builds, and diff checks passed.
- 2026-09-06: rebuilt the production `app` and `nginx` images for the isolated `restaurant-pos-split-preview` stack, ran `2026_09_04_000001_add_physical_loyalty_rewards`, recreated the application services, confirmed all six services running with healthy MySQL and Nginx, and received HTTP 200 with `{"status":"ok"}` from `/health`.
- 2026-09-07: added editable loyalty redemption to the unpaid Bill Preview, including one-tap redeem/undo controls, revised preview totals, persisted preview printing, and selection handoff to Final Bill; focused suite 5 passed with 48 assertions and the complete suite passed with 140 tests and 789 assertions.
- 2026-09-07: rebuilt the isolated preview production images, started Docker Desktop, recreated all six services, confirmed no pending migrations and healthy MySQL, and received `{"status":"ok"}` from `/health` on port 8097.
- 2026-09-14: corrected Bill Preview to show every ordered item and hide the redundant All orders/Table selector for single-source bills; focused suite 5 passed with 52 assertions and the complete suite passed with 140 tests and 793 assertions.
- 2026-09-14: rebuilt and recreated the isolated port 8097 preview with the corrected item list, confirmed no pending migrations, and received `{"status":"ok"}` from `/health`.

## Change log

### 2026-10-15

- Added cancellation after KOT for all operational staff, including waiters, from shared ticket controls and a waiter table-order link; required reason/optional notes and cancelling actor are retained.
- Added atomic unpaid-preview/table cleanup, unsent print-job cancellation and retry/expired-lease suppression, and guards against reviving cancelled tickets or closing unpaid tickets. Preserved final invoice/return protections and order history.
- Verified twelve cancellation cases and the complete 168-test suite, frontend tests/build, PHP/JavaScript syntax, and Blade rendering. Restaurant deployment and physical-device acceptance remain pending.

### 2026-10-14

- Guided and recorded the operator's deployment of `f967a0d` to the existing restaurant LAN installation after database backup/integrity validation and preservation of local environment/printer customization.
- Confirmed builds, migration/cache commands, four service restarts, HTTP health, and printer-agent communication from operator evidence. Recorded backup restore, phone/table workflow, and physical-print checks as pending.
- Reported seven additional bugs from a read-only follow-up audit; these remain open and are not part of the deployed fixes.

### 2026-10-13

- Completed and verified all ten LAN audit fixes across table workflow, atomic checkout/settlement, KOT allocation/polling, immutable reports, protected menu history, and financial concurrency.
- Added workflow, stale-request, independent-process financial concurrency, and JavaScript regression coverage. Updated split-payment validation coverage to expect failed billing to roll back its draft while retaining the existing order.
- Corrected the existing billing migration rollback order/table name and verified the complete rollback on the isolated test database.
- Recorded deployment and phone/thermal-printer acceptance as pending; no Amber or preview deployment was performed.

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
- Removed the unreferenced 11.2 MB `printPdf.exe`, legacy PHP 7.4/8.0/8.1 Sail runtimes and launcher, superseded host backup/install scripts, and the unused Laravel Sail development dependency. Retained tests, repository-maintenance files, and future HTTPS Nginx examples in source control.

### 2026-07-26

- Removed the unconditional production HTTPS scheme override so LAN deployments using an HTTP `APP_URL` load compiled CSS and JavaScript correctly while HTTPS deployments continue to follow their configured URL and trusted proxy headers.

### 2026-09-03

- Added `docs/PROJECT_OVERVIEW_AND_SPLIT_PAYMENT_PLAN.md` with the current product, feature, readiness, deployment, compliance, and limitation summary plus a three-phase implementation plan for exact two-method split tender payments.
- Tightened the repository workflow so future agents update project-state documentation after material implementations, while planned or documentation-only work remains explicitly separate from implemented functionality. Removed the unnecessary hourly state-recorder automation; state updates now happen within the implementation task.
- Implemented immutable split-tender allocations for dine-in and takeaway finalization, exact server-side cent validation, historical single-payment backfill, fiscal snapshot hashing, receipt and administrator visibility, return guidance, allocation-based daily reporting, audit details, and focused regression coverage.
- Rebuilt and smoke-tested the production stack against an isolated seeded preview database on port 8097. Preserved the inaccessible older database volume without migration or credential changes after its safety backup attempt was rejected by stale credentials.
- Fixed shared-storage permission failures by retaining PHP-FPM's required root master with `www-data` workers while running queue and scheduler Artisan commands as `www-data`; added a production-image regression assertion and verified log writes in the live preview.
- Hardened split-payment usability and adjacent billing paths: active table orders now contribute to the displayed payable total, selecting split auto-fills an exact half and remainder, compact payment panels scroll within the viewport, zero-value splits are disabled, cancel restores Cash, existing-table billing is reachable without adding another item, and high-value existing-table bills collect buyer details.
- Fixed the eight reviewed mobile and payment-interface defects: responsive POS stacking, contained modal/navigation/Bills scrolling, immediate distinct-method enforcement, explicit split-overpayment errors, accessible table action labels, and consistent `NPR` currency labels.
- After approval, made Add Notes viewport-safe on phones and replaced its saved selection on each save to prevent duplicate notes.

### 2026-09-06

- Implemented category-controlled physical stamp-card loyalty redemption for new and existing POS orders, including cashier confirmation and undo, zero-price receipt lines, full KOT/BOT and inventory quantities, immutable fiscal/audit details, and promotional-value reporting.
- Moved redemption out of order entry and into Final Bill: the cashier collects and selects cards only immediately before payment, totals and split tender update before confirmation, and the server applies rewards transactionally during finalization.
- Verified the corrected loyalty release with focused and complete automated suites, frontend, JavaScript, and Blade builds, and an isolated production Compose migration and health smoke test. Amber remains on the split-only release pending explicit loyalty deployment and phone/thermal-printer acceptance.

### 2026-09-07

- Added loyalty controls to the editable unpaid Bill Preview so a cashier can redeem or undo cards while presenting the bill, print and persist the revised unpaid preview, and carry the selection directly into Final Bill.
- Reused the existing editable bill, summary-print, and locked finalization paths without adding a migration or a separate loyalty subsystem. Finalized receipts and fiscal snapshots remain immutable.
- Verified the change with focused and complete automated suites, JavaScript syntax checks, the production Vite build, Blade rendering through HTTP tests, and diff checks.

### 2026-09-14

- Fixed the unpaid Bill Preview so it displays all ordered menu lines rather than only loyalty-eligible candidates, including quantity, unit price, and regular line total.
- Hid the billing-source selector when a bill has only one source; retained it for transferred or merged orders where choosing a source is meaningful.
- Made staff email optional in the users table and administrator create/edit workflows. Username login remains unchanged; accounts without email require an administrator to reset forgotten passwords.
- Confirmed loyalty reporting remains available under **Reporting → Discounts**, with free-item quantity, original promotional value, invoice, item, category, and approving cashier; normal item/category reports count served reward units without treating them as revenue.
- Verified optional staff email and loyalty reporting with 16 focused tests and 119 assertions, then passed the complete suite with 140 tests and 795 assertions.
- Rebuilt the isolated preview, ran `2026_09_14_000001_make_user_email_optional`, and confirmed `{"status":"ok"}` from `/health`. Existing staff emails and transaction history were preserved; Amber remains unchanged.
- Fixed existing-order visibility on the main POS order screen, waiter order screen, and table Final Bill modal so a displayed existing total is accompanied by its menu lines. Reused the existing billing-source item data and left VAT calculation unchanged.
- Audited related billing surfaces: unpaid Bill Preview, finalized bill detail, and printed receipts were already itemized; table, bill, and credit index pages remain summary lists by design.
- Verified the visibility change with 12 focused tests and 128 assertions, JavaScript syntax validation, and the complete suite with 140 tests and 802 assertions.
- Rebuilt and recreated the isolated port 8097 preview with the itemized existing-order views, confirmed no pending migrations, all six services running with healthy MySQL and Nginx, and HTTP 200 with `{"status":"ok"}` from `/health`.
