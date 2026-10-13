# Restaurant POS — Project Overview and Split-Payment Plan

Last reviewed: 2026-10-13
Branch: `local-lan-deployment`
Revision reviewed: phased LAN reliability repairs in the working tree

## Executive summary

Restaurant POS is a Laravel-based restaurant operations system designed for one independently configured restaurant per installation. The core POS, kitchen workflow, billing, printing, reporting, inventory, security, backups, local-network access, and IRD CBMS delivery foundation are implemented.

The application is suitable for normal local restaurant operation with CBMS disabled. It is not yet approved or activated for live IRD CBMS use. Phase 7A production-readiness tooling and Phase 7B controlled acceptance tooling are complete; the external IRD acceptance exercise and Phase 7C automatic-delivery activation remain outstanding.

Split tender is implemented for both dine-in and immediate takeaway billing. An operator can retain the existing single-payment flow or allocate a finalized total across exactly two distinct non-credit methods, such as NPR 400 cash plus NPR 600 Fonepay on a NPR 1,000 bill. Allocations are immutable, included in receipts and fiscal snapshots, and reported under their actual methods.

Physical stamp-card rewards are also implemented. Categories opt into eligibility; ordering only marks eligible products. For an existing table bill, a cashier can redeem completed cards while showing the editable unpaid Bill Preview, print and persist that preview, and carry the selection into Final Bill. A cashier can also redeem directly in Final Bill before choosing payment. No customer account is required, and the rewarded item remains on the receipt at NPR 0.00 while its regular price and approval details are preserved for audit and reporting.

## Documentation maintenance policy

Project-state documentation is updated as part of the same agent task that completes and verifies a material implementation. Planning, discussion, and documentation-only edits do not change feature readiness and must not be reported as implemented work. There is no recurring time-based documentation job.

## Current platform

- Backend: PHP 8.2 and Laravel 12.
- Database: MySQL 8.
- Frontend: Vite, Tailwind CSS, Alpine.js, Axios, Chart.js, Laravel Echo, Flatpickr, Pusher JS, and SortableJS.
- Runtime: Docker Compose with PHP-FPM, Nginx, MySQL, Soketi, queue worker, and scheduler services.
- Deployment: one installation per restaurant, available on a trusted local network through a reserved server IP.
- Current development branch: `local-lan-deployment`.
- Latest recorded automated verification: 156 tests passed with 875 assertions, plus two JavaScript workflow regressions.

## Implemented features

### Restaurant and staff operations

- Configurable restaurant name, address, PAN/VAT details, VAT rate, service charge, receipt settings, and payment options.
- Administrator, owner, manager, biller, waiter, kitchen, and inventory-oriented access controls.
- Staff account creation, deactivation, password controls, login rate limiting, MFA, recovery codes, and recent-authentication checks. Staff email is optional; accounts without email use administrator-managed password resets.
- Audit logging with sensitive-value redaction.

### Ordering and tables

- Dine-in and takeaway ordering.
- Table locations and table availability states.
- Waiter order entry from phones on the local network.
- Kitchen order flow with private realtime updates.
- KOT and BOT routing by menu production area.
- Table transfer by authorized users.
- Protection against adding orders after bill finalization.
- Editable order summaries before final billing.
- Existing dine-in items remain visible when adding another order from the main POS or waiter screen.
- Phone-safe Add Notes dialog behavior that replaces the saved selection on each save instead of duplicating notes.
- Category-controlled physical stamp-card eligibility and cashier redemption only in Final Bill, for new or existing table orders and immediate takeaway bills.

### Billing and payments

- Cash, card, eSewa, Khalti, Fonepay, and credit payment methods.
- Legacy recognition of Fonepay QR and bank transfer values.
- Fixed and percentage discounts with reasons and authorization records.
- Buyer name and PAN validation for invoices above the configured threshold.
- Immutable invoice numbers, fiscal-year numbering, invoice snapshots, line snapshots, and document hashes.
- Exact cent-based VAT reconciliation.
- Credit customers, partial credit settlement, payment history, references, and outstanding balances.
- Finalized-bill locking and safe duplicate printing.
- Zero-price loyalty reward lines with original-price, cashier, category, and redemption-time snapshots.
- Itemized unpaid Bill Preview, Final Bill, finalized bill detail, and printed receipts; index pages remain concise summaries with detail actions.

Split tender currently supports exactly two distinct non-credit methods; mixed credit/direct tender and more than two allocations are intentionally excluded. On selection, the interface proposes an exact half-and-remainder split, prevents the same method being chosen twice, and reports an amount equal to or above the bill total without replacing the operator's input.

### Printing

- Windows print-station agent for ESC/POS thermal printers.
- Counter receipt, kitchen KOT, and bar BOT mappings.
- Durable print jobs with pending, processing, printed, failed, and retry states.
- Station tokens, heartbeats, online/offline status, printer mappings, attempt history, and administrator retry controls.
- Customer and optional restaurant receipt copies.
- Browser preview when unattended printing is unavailable.

### Inventory and purchasing

- Stock items, units, categories, suppliers, purchase invoices, and supplier payments.
- Ingredient deductions from finalized sales.
- Stock movements with running balances and reasons.
- Low-stock and operational reporting.
- Sale-time deduction snapshots for return handling.
- Returned products default to waste; authorized users can explicitly restore genuinely reusable stock once.

### Reports and accounting records

- Bill listings and date filtering.
- Daily payment and collection summaries by method.
- Gross collections, payment refunds, net collections, invoice sales, returns, discounts, and VAT reporting.
- Item and category sales reports based on immutable sale-time values.
- Credit balances, partial payments, and settlement history.
- Return-aware reporting and auditable refund transactions.
- Stamp-card redemption quantity and promotional-value reporting; item/category revenue excludes free units while prepared quantities remain counted.

### IRD and CBMS work

- Immutable fiscal invoice snapshots and credit-note snapshots.
- Durable sales-bill and bill-return outboxes.
- Official `/api/bill` and `/api/billreturn` payload mapping.
- Credential isolation from stored payloads and user interfaces.
- Retryable queue delivery, scheduler recovery, idempotency safeguards, and operations visibility.
- Full and partial returns with cumulative quantity and tax reconciliation.
- Production preflight checks for configuration, migrations, scheduler, clock, credentials, and clean outboxes.
- Controlled acceptance mode that permits exactly one explicitly confirmed live record while suppressing automatic delivery.

Live status:

- `CBMS_ENABLED=false` is the safe local-operation setting.
- No live IRD acceptance test has been completed.
- Software enlistment, taxpayer credentials, IRD coordination, and restaurant-specific approval are still external requirements.
- Phase 7C automatic live delivery must not be enabled before successful controlled invoice and return acceptance.

### Deployment and operations

- Dockerized installation without host PHP, Composer, Node.js, or MySQL.
- Private-LAN access for the server PC and staff phones.
- Same-origin kitchen realtime connection from localhost or the reserved LAN address.
- Container restart policy `unless-stopped`.
- Windows scheduled-task support for the print-station agent.
- Database backup, retention, health, status, alerting, scheduler heartbeat, disk monitoring, and clock-skew checks.
- Shared storage is initialized by the container entrypoint; PHP-FPM uses its standard root master and `www-data` workers, while queue and scheduler commands run as `www-data` so logs, sessions, views, and cache files remain writable across services.

## Current readiness

The 2026-10-13 repair release is implemented and verified in the repository. It has not been deployed to Amber or the port 8097 preview. The earlier deployment checks below describe those earlier releases.

| Area | State |
|---|---|
| Core ordering and table workflow | Ready for local operation |
| Billing, VAT, receipts, and reporting | Ready for local operation |
| Kitchen realtime workflow | Implemented and tested |
| Windows thermal printing | Implemented and tested |
| Docker LAN deployment | Implemented and smoke-tested |
| Backups and operational monitoring | Implemented; each installation must verify its own schedule and restore process |
| Returns and internal return accounting | Implemented |
| IRD controlled-acceptance tooling | Implemented, live acceptance pending |
| IRD automatic production delivery | Not authorized or enabled |
| Split payment | Implemented and regression-tested; final phone smoke test recommended after redeployment |
| Phone POS and Bills layout | Responsive overflow fixes implemented and regression-tested |
| Physical stamp-card loyalty | Unpaid-preview and final-payment flows implemented, regression-tested, and running in the isolated preview; Amber deployment plus phone/thermal smoke test pending |
| LAN reliability repair release | All ten audit fixes implemented and tested; live rollout and staff-device/printer acceptance pending |

The isolated Compose project `restaurant-pos-split-preview` uses port 8097 and separate seeded volumes. Production images containing the newer unpaid-preview flow were built and all six services were recreated on 2026-09-07. No migrations were pending, MySQL reported healthy, and `/health` returned `{"status":"ok"}`. The older local database volume was not migrated because its stored credentials no longer match the current deployment environment; it remains untouched pending credential recovery or an explicitly authorized recovery procedure.

The port 8097 preview contains the split-payment, mobile/currency, unpaid-preview loyalty, and existing-order item-visibility changes. It was rebuilt on 2026-09-14 with all six services running, healthy MySQL and Nginx, no pending migrations, and a passing HTTP health check. Automated and container health checks pass; phone layout and physical thermal-printer acceptance remain operator checks.

## Known limitations

- The repair release prevents new duplicate KOT allocation and protects existing order lines, but does not rewrite old duplicate tickets or reconstruct previously deleted history. Reports for old bills without fiscal snapshots retain a legacy fallback whose missing sale-time identity cannot be recovered.
- Split tender currently supports exactly two direct payment methods and does not support mixing credit with a direct collection.
- Fonepay, card, eSewa, Khalti, and bank-transfer selections are accounting records; the POS does not initiate or verify payment-gateway transactions.
- Payment refunds are internal records and do not call bank, wallet, card, or cash-management APIs.
- Credit-note printing currently uses the authenticated browser workflow rather than the unattended print queue.
- All current menu items use the standard 13% VAT snapshot; exempt or mixed-tax item support should be added only when required.
- Historical returns without deduction snapshots cannot restore inventory automatically.
- Live CBMS approval and acceptance remain external work.
- The fiscal representation of a promotional item at NPR 0.00 must be confirmed during controlled CBMS acceptance with the restaurant's tax adviser/IRD before automatic delivery is enabled.
- Loyalty is intentionally physical-card only: no customer accounts, digital stamps, card serial tracking, expiry, tiers, or electronic duplicate-card prevention.

## 2026-10-13 LAN reliability repairs

Completed phases:

1. **Workflow:** table clicks follow the current status; cancelling the last unpaid ticket releases the table; invoiced tickets require returns; checkout failures roll back orders and bills; settlement closes orders/bills atomically; KOT polling detects other operators and status changes without empty-screen or waiter-view reload loops.
2. **Historical integrity:** daily KOT numbers are reserved under a database lock; referenced menus cannot be deleted; item/category reports retain immutable sale-time identity and prices, with the existing date filters and legacy fallback.
3. **Financial integrity:** purchase posting/voiding use current locked invoice state; invoice-linked supplier payments require posted invoices and cannot overpay; return issuance reads accumulated quantities only after locking the bill. Real independent-process races verify these protections.
4. **Verification:** the full suite passed with 156 tests and 875 assertions, two JavaScript regressions passed, and PHP syntax, production Vite build, Docker test-image build, Blade compilation, and diff checks passed. An additional verified migration rollback defect was fixed by dropping `bill_orders` before `bills`; complete rollback succeeded on the disposable test database.

No application fix is blocked. Deployment remains pending: back up the actual installation, rebuild and deploy the application/frontend/worker images, then check table finalization/closure/reuse, cancellation, KOT changes across two staff devices, and physical receipts. These repairs add no forward migration; previously pending release migrations must still be checked. CBMS activation remains separate and was not changed or tested against live IRD.

Regression commands use the isolated test Compose project, never the restaurant database:

```powershell
docker compose -f docker-compose.yml -f docker-compose.test.yml build app
docker compose -f docker-compose.yml -f docker-compose.test.yml run --rm app php artisan test
docker compose -f docker-compose.yml -f docker-compose.test.yml run --rm --no-deps app node --test tests/js/lan-workflow.test.cjs
```

Database tests share one isolated schema and must run sequentially; the financial workers intentionally compete within a single test. The original findings, corrections, verification, and historical-data limits are recorded in `PROJECT_STATE.md`.

# Implemented Feature: Physical Stamp-card Loyalty

Implementation completed and regression-verified through the unpaid-preview flow on 2026-09-14. An administrator marks eligible categories, and any menu item in at least one eligible category can be redeemed by a POS cashier. Ordering and KOT submission do not redeem cards or alter prices. For an existing table order, the cashier opens Bill Preview, reviews every ordered item with quantity and pricing, uses **Redeem one** for each physically collected completed card, reviews the revised unpaid total, and can undo, print the preview, or proceed to payment. The billing-source selector stays hidden for ordinary single-table bills and appears for transferred or merged orders. Printing persists the selection on the editable bill; Proceed to Payment carries it into Final Bill. Direct Final Bill redemption remains available. One card equals one free item unit, and selections can be undone until finalization.

The server validates eligibility and quantity independently of the browser and applies rewards inside the locked bill-finalization transaction. Rewarded quantities remain on KOT/BOT tickets and consume inventory normally, but their sales price is zero. Final fiscal snapshots preserve separate paid and reward lines, the regular price, category, cashier, approval time, and proportional inventory deduction. Receipts label the reward. **Reporting → Discounts** shows total free-item quantity and value plus each invoice, item, category, quantity, and approving cashier. Item/category sales reports count all served units while excluding rewarded units from revenue.

This feature deliberately does not create customer profiles, points balances, digital card identifiers, manager approval, or payment-provider integration. Existing categories remain ineligible until explicitly enabled. The refreshed isolated preview is running with all migrations applied and a passing HTTP health check; Amber still requires deployment and a staff-device/thermal-receipt smoke test.

## Implemented Feature: Optional staff email

Implemented and preview-verified on 2026-09-14. Administrators may create or edit a staff account without entering an email address; username and password remain required and login continues to use username. Existing email addresses are retained. Because email password recovery cannot work without an address, an administrator must reset the password for an account that has no email.

# Implemented Feature: Split Tender Payments

Implementation completed and verified on 2026-09-03. The sections below record the delivered contract and current boundaries rather than future work.

## User outcome

At final billing, an operator can choose either:

1. one existing payment method for the full amount; or
2. **Split payment**, choose two non-credit methods, start from the automatically proposed exact half-and-remainder allocation, and optionally adjust the first amount while the POS recalculates the second amount.

For dine-in tables, the payable total includes all active KOT orders already assigned to the table as well as newly selected items. The split controls and final-bill dialog are height-bounded and vertically scrollable so the receipt and action controls remain reachable on shorter desktop and phone viewports.

Example for a NPR 1,000 bill:

| Method | Amount |
|---|---:|
| Cash | NPR 400.00 |
| Fonepay | NPR 600.00 |
| Total | NPR 1,000.00 |

The operator may replace Fonepay with card, eSewa, or Khalti. The first release supports exactly two allocations. The database shape permits more allocations later without another redesign, but legacy Fonepay QR and bank-transfer values are not active tender choices.

## Scope decisions

- Preserve the current single-payment experience.
- Allow exactly two distinct, non-credit methods in the initial interface.
- Calculate all validation in integer cents on the server.
- Require every allocation to be greater than zero.
- Require allocation totals to equal the finalized `grand_total` exactly.
- Allow an optional transaction reference for non-cash methods.
- Record payments only; do not add Fonepay or other payment-gateway integration.
- Do not allow credit as one side of a split in the first release. Mixed paid/credit settlement changes receivable, return, and CBMS accounting and should be a separate feature if required.
- Keep the entire finalization, fiscal snapshot, payment allocation creation, inventory deduction, and outbox creation in one database transaction.

## Request format

Existing clients may continue sending:

```text
paymentMethod=cash
```

A split bill sends:

```text
paymentMethod=split
payments[0][method]=cash
payments[0][amount]=400.00
payments[1][method]=fonepay
payments[1][amount]=600.00
payments[1][reference_no]=optional-reference
```

The server must never trust the browser-calculated remainder without validating the final bill total again.

## Data model

The implementation adds one `bill_payments` table:

| Column | Purpose |
|---|---|
| `id` | Primary key |
| `bill_id` | Parent bill |
| `payment_method` | Cash, card, wallet, or transfer method |
| `amount` | Immutable `DECIMAL(12,2)` allocation |
| `reference_no` | Optional external transaction reference |
| `received_at` | Finalization/collection time |
| `recorded_by` | Operator who finalized the bill |
| timestamps | Audit timestamps |

Compatibility rules:

- A single-method bill receives one `bill_payments` row and retains its current `bills.payment_method` value.
- A split bill receives two rows and stores `bills.payment_method = split` as the summary label.
- Existing finalized non-credit bills are backfilled with one allocation using their current method, total, finalization time, and operator where available.
- Existing credit bills are not backfilled as direct collections; their actual collections remain in `credit_payments`.
- Payment allocations become immutable once the bill is finalized.

## Completed implementation phases

### Phase 1 — Payment allocation foundation (complete)

1. Add the `bill_payments` migration, model, `Bill::payments()` relation, and `Split` display label.
2. Backfill historical finalized non-credit bills with one allocation per bill.
3. Extend the shared bill-finalization path to accept either the existing single method or two split allocations.
4. Validate allowed methods, distinct methods, positive amounts, optional references, and exact cent equality with `grand_total`.
5. Create allocations inside the existing locked finalization transaction.
6. Include the stable allocation breakdown in the fiscal invoice document hash for newly finalized invoices without changing historical hashes.
7. Reject mixed credit/direct allocations.

Exit criteria:

- Existing single-method finalization behaves unchanged.
- NPR 1,000 can finalize as NPR 400 cash plus NPR 600 Fonepay.
- Overpayment, underpayment, zero, negative, duplicate-method, unsupported-method, and mixed-credit requests fail without finalizing the bill.

### Phase 2 — POS, receipt, and reporting integration (complete)

1. Add a `Split payment` option to both dine-in final billing and immediate takeaway billing.
2. Show two method selectors, the first amount input, an automatically calculated second amount, and optional reference inputs.
3. Display the total, allocated amount, and remaining amount before finalization.
4. Print each allocation and amount on browser and ESC/POS receipts.
5. Change daily payment reporting to aggregate `bill_payments.amount` by method instead of assigning the full bill total to `bills.payment_method`.
6. Continue merging direct allocations with `credit_payments` and subtracting `payment_refund` transactions as the reporting flow does today.
7. Show the payment breakdown on administrator bill details and reprints.

Exit criteria:

- Dine-in and takeaway split payments work from desktop and phone layouts.
- Receipt allocation totals equal the printed grand total.
- Daily cash and Fonepay totals receive only their allocated amounts with no double counting.
- Historical single-method reporting remains unchanged after backfill.

### Phase 3 — Returns, audit, and release hardening (application work complete)

1. Ensure a return against a split-paid invoice records the operator-selected refund method and amount through the existing immutable refund transaction flow.
2. Show the original payment allocation breakdown while issuing a return so the operator can choose the actual refund route.
3. Audit the finalized payment breakdown, operator, and references without storing wallet credentials or secrets.
4. Added focused validation, reporting, fiscal snapshot, thermal receipt, dine-in, takeaway, and single-payment compatibility coverage; existing full-suite tests continue to cover finalized-bill locking, reprints, and returns.
5. Run the complete automated suite and production asset build.
6. Production images and an isolated seeded deployment have passed migration and HTTP smoke checks. Migration of the older local restaurant data and reconciliation against the physical cash drawer and Fonepay record remain operator acceptance steps after its database access is recovered.

Exit criteria:

- Split-paid invoices can be partially or fully returned without corrupting collection reports.
- Duplicate finalization cannot create duplicate allocations.
- Audit, receipt, bill view, daily summary, and return records agree on the payment breakdown.
- Deployment and rollback instructions are documented and verified on a copy of production data.

## Minimum test matrix

- Single cash payment remains unchanged.
- Cash plus Fonepay exact split succeeds.
- Cash plus card exact split succeeds.
- One-cent remainder is calculated correctly.
- Underpayment and overpayment are rejected.
- Zero and negative allocations are rejected.
- Two identical payment methods are rejected; the operator should use a single payment instead.
- Credit mixed with another method is rejected.
- Invalid payment methods are rejected.
- Optional online reference is stored and printed where appropriate.
- Dine-in and takeaway flows both create the same allocation records.
- Concurrent or repeated finalization creates only one final invoice and one allocation set.
- Daily reporting groups each allocation under its actual method.
- Full and partial returns preserve correct net collections.
- Historical single-method bills report correctly after migration.

## Principal implementation files

- New migration and `BillPayment` model.
- `app/Models/Bill.php`.
- `app/Helpers/BillHelper.php`.
- `app/Http/Controllers/POS/PosController.php`.
- `app/Http/Controllers/Order/OrderController.php`.
- `app/Http/Requests/OrderSubmitRequest.php`.
- `resources/views/pos/tables.blade.php`.
- `resources/views/pos/pos-index.blade.php` and its shared order-submission JavaScript flow.
- `resources/views/bill/receipt.blade.php`.
- `app/Helpers/Printers/BillPrinter.php`.
- `app/Http/Service/ReportingService.php`.
- Administrator bill views and focused feature tests.

## Not part of this feature

- Fonepay API integration or automatic payment confirmation.
- QR generation.
- Card-terminal integration.
- Automated refunds to wallets or banks.
- Split credit/receivable payments.
- More than two payment allocations in the first interface.

These should be added only when a restaurant has a confirmed operational requirement and the relevant provider contract.
