# CBMS go-live runbook

Use this runbook for Phase 7B acceptance and the later production cutover. Never place taxpayer credentials in this file, source control, logs, or screenshots.

## Production readiness

1. Keep `CBMS_ENABLED=false`.
2. Enter the restaurant's exact legal name, address, and nine-digit PAN in its settings.
3. Put `CBMS_USERNAME` and `CBMS_PASSWORD` only in the deployment `.env`.
4. Deploy the app, queue, and scheduler services, then run migrations.
5. Create a database backup and validate it with `backup:validate`.
6. Wait up to three minutes for the scheduler heartbeat, then run `php artisan cbms:preflight` in the app container.
7. Resolve every failure. Do not discard or rewrite unresolved outbox records to make the check pass.

## Credential rotation

1. Set `CBMS_ENABLED=false` and redeploy all PHP services so cached configuration is refreshed.
2. Replace the credentials only in the deployment `.env` and redeploy again.
3. Run `php artisan cbms:preflight` while delivery remains disabled.
4. Re-enable delivery only after the new credentials and IRD access are approved.

## Failure or rollback

1. Set `CBMS_ENABLED=false` and redeploy the app, queue, and scheduler services.
2. Keep the POS operating offline; invoices remain immutable in the CBMS outbox.
3. Preserve failed and pending records for diagnosis and retry. Never delete them or edit their payloads.
4. Check the admin System Status and CBMS pages, then use the existing audited manual retry only after the cause is fixed.

## Phase 7B handoff

Do not send a controlled invoice or credit note until IRD confirms the fiscal-year, numbering, and `isrealtime` rules and the restaurant approves the acceptance window.

1. Complete Phase 7A preflight with an empty outbox while `CBMS_ENABLED=false`.
2. Set `CBMS_ENABLED=true` and `CBMS_ACCEPTANCE_MODE=true`, then redeploy all PHP services. Acceptance mode suppresses finalization dispatch, credit-note dispatch, scheduled recovery, queued jobs, and dashboard retries.
3. Create exactly one controlled invoice. Confirm the CBMS dashboard shows exactly one unresolved record.
4. Run `php artisan cbms:accept invoice ID --confirm="SEND TO IRD"`.
5. Require response `200`, then confirm the invoice in the CBMS External Portal Sales Register Sync report.
6. Issue exactly one controlled credit note for that accepted invoice.
7. Run `php artisan cbms:accept credit-note ID --confirm="SEND TO IRD"`.
8. Require response `200`, confirm the return in the portal, and record IRD/restaurant approval outside source control.
9. Keep acceptance mode enabled until Phase 7C authorizes automatic production delivery.
