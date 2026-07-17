# Restaurant POS

Restaurant POS is a Laravel-based point-of-sale and restaurant operations system intended for one separately deployed restaurant per installation.

The default configuration is tailored for Nepal and includes NPR, PAN/VAT billing, Nepali fiscal-year invoice numbering, and local payment methods. Restaurant identity and operational settings are configured per installation through `.env` and the restaurant settings screen.

## Main modules

- Point of sale, dine-in, and takeaway ordering
- Table, waiter, kitchen, KOT, and biller workflows
- Thermal receipt and kitchen/bar printing through print stations
- Billing, credit settlement, discounts, and reports
- Inventory, purchases, suppliers, and stock movements
- Role-based access, MFA, sessions, audit logs, backups, and system monitoring

## Local setup with Docker

Docker Desktop with Docker Compose v2 is required. No host PHP, Composer, Node.js, or MySQL installation is needed.

On Windows:

```powershell
.\start-local.ps1
```

On macOS or Linux:

```bash
chmod +x start-local.sh
./start-local.sh
```

Open `http://localhost:8097` and sign in with the `ADMIN_USERNAME` and `ADMIN_PASSWORD` configured in `.env`.

The first run creates `.env` from `.env.docker.example`, builds the images, migrates and seeds the database, and starts the application. Stop it with:

```bash
docker compose down
```

Do not use the development credentials or example secrets in production. Configure a unique `APP_KEY`, database credentials, administrator password, business profile, allowed hosts, mail settings, backup storage, and realtime credentials for every restaurant installation.

## Verification

Run the automated test environment with:

```bash
docker compose -f docker-compose.yml -f docker-compose.test.yml run --rm app php artisan test
```

Production deployments should use immutable application images, run database backups before migrations, and verify the `/health` endpoint after startup.
