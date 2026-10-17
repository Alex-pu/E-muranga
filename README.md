# eMuranga Service Desk

I built this PHP and PostgreSQL app as a community service-request demo.

## Features

- Citizen registration, sign-in, and private request history.
- Report a local issue with its category, location, description, and optional photo.
- Track requests through Pending, In Progress, and Resolved.
- Staff dashboard with filters, analytics, and status-change history.
- Emergency-call UI mockup for a proposed free internet call without airtime. It does not place calls or connect to emergency services.

## Install and run

Requirements: PHP 8.5 with `pdo_pgsql`, PostgreSQL, and Git.

Install the packages on Ubuntu:

```bash
sudo apt update
sudo apt install -y git postgresql php8.5-cli php8.5-pgsql php8.5-fpm
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
```

Replace the example password. Keep this file outside the web root and restrict its permissions.

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

Use test data. This demo is not an official county service.
