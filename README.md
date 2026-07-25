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

### Staff phones on the local network

Give the server PC a fixed LAN address, connect it and all staff devices to the same private network, then set these values in `.env`:

```dotenv
APP_BIND_ADDRESS=0.0.0.0
APP_URL=http://192.168.1.50:8097
APP_HEALTH_HOST=192.168.1.50
PUBLIC_APP_HOSTS=192.168.1.50,localhost
PRIVATE_APP_HOSTS=192.168.1.50,localhost
PRINT_SERVICE_HOSTS=192.168.1.50,localhost
SESSION_SECURE_COOKIE=false
SECURITY_HSTS_ENABLED=false
```

Replace `192.168.1.50` with the reserved server address, allow TCP port `8097` from the private LAN in the host firewall, and restart with `docker compose up -d`. Staff then open `http://192.168.1.50:8097`. Never forward this port from the internet or place the POS on guest Wi-Fi.

## Verification

Run the automated test environment with:

```bash
docker compose -f docker-compose.yml -f docker-compose.test.yml run --rm app php artisan test
```

Production deployments should use immutable application images, run database backups before migrations, and verify the `/health` endpoint after startup.
