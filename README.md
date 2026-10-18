# eMuranga Service Desk

I built this PHP and PostgreSQL app as a community service-request demo.

## Features

- Citizen registration, sign-in, and private request history.
- Report a local issue with its category, location, description, and optional photo.
- Track requests through Pending, In Progress, and Resolved.
- Request a monthly parking sticker using an M-Pesa STK Push; paid stickers are printable from the citizen dashboard.
- Staff dashboard with filters, analytics, and status-change history.
- Emergency-call UI mockup for a proposed free internet call without airtime. It does not place calls or connect to emergency services.
- County logo and community, roads, and water imagery in `assets/`.

## Install and run

Requirements: PHP 8.5 with `pdo_pgsql` and `curl`, PostgreSQL, and Git.

Install the packages on Ubuntu:

```bash
sudo apt update
sudo apt install -y git postgresql php8.5-cli php8.5-pgsql php8.5-curl php8.5-fpm
```

Create a database role and database:

```bash
sudo -u postgres createuser --pwprompt emuranga_app
sudo -u postgres createdb --owner=emuranga_app emuranga
```

Create `/etc/emuranga/app.env` with the database connection:

```ini
PGHOST=127.0.0.1
PGPORT=5432
PGDATABASE=emuranga
PGUSER=emuranga_app
PGPASSWORD=your-database-password
UPLOAD_DIR=/var/lib/emuranga/uploads
EMURANGA_PUBLIC_URL=https://your-public-hostname.example
EMURANGA_DARAJA_ENV=sandbox
EMURANGA_DARAJA_CONSUMER_KEY=
EMURANGA_DARAJA_CONSUMER_SECRET=
EMURANGA_DARAJA_SHORTCODE=
EMURANGA_DARAJA_PASSKEY=
EMURANGA_DARAJA_CALLBACK_TOKEN=replace-with-a-random-secret-at-least-32-characters
EMURANGA_PARKING_MONTHLY_FEE=1
```

Replace the example password. Keep this file outside the web root and restrict its permissions.
For Daraja, add your sandbox credentials for testing. Use `production` only when you are ready
to use live credentials and a publicly reachable HTTPS hostname. Generate a callback token
privately (for example, `openssl rand -hex 32`); never commit credentials or the token.
The callback URL is built from `EMURANGA_PUBLIC_URL` and the private token. `EMURANGA_PARKING_MONTHLY_FEE`
defaults to KSh 1 for this demo and can be changed to the approved fee.

The parking flow sends an STK Push to the supplied Kenyan mobile number. A sticker is issued
only after the Daraja callback confirms the amount, phone number, and M-Pesa receipt. The
callback endpoint must be reachable over HTTPS from Safaricom/Daraja. Failed or inconsistent
callbacks do not produce a sticker; review payments manually before taking any further action.
Run `php bin/migrate.php` after updating the app.

Clone and initialize the app:

```bash
git clone https://github.com/Alex-pu/E-muranga.git
cd E-muranga
php bin/migrate.php
```

Run locally:

```bash
php -S 127.0.0.1:8088
```

For the hosted version, configure PHP-FPM and Nginx to serve the project on `127.0.0.1:8088`, then point the existing Cloudflare Tunnel hostname to that address. Keep other tunnel routes unchanged.

Use test data. This demo is not an official county service. Do not enable live payments until
the county has approved the fee, payment terms, callback hosting, and reconciliation process.
