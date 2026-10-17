# eMuranga Community Service Desk

A PHP 8.5 and PostgreSQL internship demo for reporting and following up community public-service issues. Citizens register, submit a category, location, description and optional photo, then track progress. Staff review and filter reports, advance cases through **Pending → In Progress → Resolved**, and see open/resolved analytics. Every staff status change is recorded in an audit trail.

This is an independent educational demo, not an official Murang'a County service.

## Features

- Citizen registration and sign-in with PHP password hashing and server-side sessions.
- Role-gated staff dashboard; public sign-up always creates a citizen account.
- Issue categories for streetlights, water, garbage, roads/drainage, public health and other.
- Report reference numbers, location, contact phone, description and optional JPG/PNG/WebP evidence image (5 MB maximum).
- Images stored outside the public document tree and served only to the report owner or staff.
- Private citizen report history and open/resolved counts.
- Staff filters, category breakdown, case workflow and status-change audit trail.
- PostgreSQL parameterized queries, CSRF protection, prepared status transitions, escaping and upload validation.

## Deploy on the existing Ubuntu server

Use a **new subdomain** such as `emuranga.korebench.co.ke`. Do not edit or disable the current POS, RunCommunity, or Adminer Nginx sites. The hostname for eMuranga is separate from those running sites.

### Before setup: recover PHP-FPM from the previous attempt

The earlier Neon-based attempt may have left an invalid eMuranga pool file containing the old database URL, which caused PHP-FPM's configuration test to fail. This removes only that temporary eMuranga pool file, validates the remaining PHP-FPM pools, and restarts FPM so existing PHP sites can run. It does not alter any Nginx site:

```bash
sudo rm -f /etc/php/8.5/fpm/pool.d/emuranga.conf
sudo php-fpm8.5 -t
sudo systemctl restart php8.5-fpm
sudo systemctl is-active php8.5-fpm
```

The Neon URL from that attempt should be treated as exposed; rotate it in Neon even though the new deployment uses local PostgreSQL.

### 1. DNS

In the domain's DNS provider, add an **A** record:

- Name: `emuranga`
- IPv4 address: this server's public address
- Proxy: DNS-only while requesting the first TLS certificate

Wait until `getent ahostsv4 emuranga.korebench.co.ke` resolves to the server's public IP.

### 2. Install PostgreSQL and PHP support

The app requires PHP 8.5, FPM, and `pdo_pgsql`. Install PostgreSQL locally if it is not already running:

```bash
sudo apt update
sudo apt install -y postgresql php8.5-cli php8.5-fpm php8.5-pgsql
sudo systemctl enable --now postgresql
php -m | grep -iE 'PDO|pgsql'
```

Expected modules include `PDO`, `pdo_pgsql`, and `pgsql`.

### 3. Create a private local PostgreSQL role and database

Run this in Bash on the server. It generates a strong database password, does not put the password in your shell history, makes a temporary owner-only SQL file, and stores the app's environment outside the Git checkout with restricted permissions.

```bash
set -euo pipefail
DB_PASSWORD="$(openssl rand -hex 32)"
DB_SQL="$(mktemp)"
chmod 600 "$DB_SQL"
trap 'sudo rm -f "$DB_SQL"; unset DB_PASSWORD' EXIT

sudo -u postgres psql -v ON_ERROR_STOP=1 <<'SQL'
SELECT 'CREATE ROLE emuranga_app LOGIN'
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'emuranga_app')
\gexec
SQL
printf "ALTER ROLE emuranga_app WITH LOGIN PASSWORD '%s';\n" "$DB_PASSWORD" > "$DB_SQL"
sudo chown postgres:postgres "$DB_SQL"
sudo -u postgres psql -v ON_ERROR_STOP=1 -f "$DB_SQL"
sudo chown "$(id -u):$(id -g)" "$DB_SQL"

if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname = 'emuranga'" | grep -q 1; then
  sudo -u postgres createdb --owner=emuranga_app emuranga
else
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c 'ALTER DATABASE emuranga OWNER TO emuranga_app;'
fi

sudo install -d -o root -g alec -m 0750 /etc/emuranga
{
  printf 'PGHOST=127.0.0.1\n'
  printf 'PGPORT=5432\n'
  printf 'PGDATABASE=emuranga\n'
  printf 'PGUSER=emuranga_app\n'
  printf 'PGPASSWORD=%s\n' "$DB_PASSWORD"
  printf 'UPLOAD_DIR=/var/lib/emuranga/uploads\n'
} > "$DB_SQL"
sudo install -o root -g alec -m 0640 "$DB_SQL" /etc/emuranga/app.env

sudo install -d -o alec -g alec -m 0700 /var/lib/emuranga/sessions
sudo install -d -o alec -g alec -m 0700 /var/lib/emuranga/uploads
```

The environment file is readable by the dedicated PHP-FPM user (`alec`) and not exposed through the website. `config.php` loads it for both FPM and CLI. Do not copy database credentials into Git, an Nginx file, or chat.

### 4. Clone/update and initialize

```bash
mkdir -p ~/apps
if [ -d ~/apps/e-muranga/.git ]; then
  git -C ~/apps/e-muranga pull --ff-only
else
  git clone https://github.com/Alex-pu/E-muranga.git ~/apps/e-muranga
fi
cd ~/apps/e-muranga
php bin/migrate.php
```

This initializes or migrates the app tables. It preserves the old demo rows and maps their previous statuses onto the new workflow.

### 5. Create a staff account

Use the email address you want for the administrator. Enter a long password at the hidden prompt; it is not a public signup option.

```bash
cd ~/apps/e-muranga
read -rsp 'Staff admin password (12+ characters): ' ADMIN_PASSWORD
printf '\n'
export ADMIN_PASSWORD
php bin/create-admin.php admin@example.org
unset ADMIN_PASSWORD
```

Replace `admin@example.org` with the staff sign-in email. This command creates the admin or promotes/resets the named existing account to admin.

### 6. Configure the isolated PHP-FPM pool

This pool runs as `alec` and reads database settings from `/etc/emuranga/app.env`. The existing PHP-FPM pool and other applications are left alone.

```bash
sudo tee /etc/php/8.5/fpm/pool.d/emuranga.conf >/dev/null <<'EOF'
[emuranga]
user = alec
group = alec
listen = /run/php/php8.5-fpm-emuranga.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
clear_env = yes
php_admin_value[session.save_path] = /var/lib/emuranga/sessions
php_admin_value[upload_max_filesize] = 6M
php_admin_value[post_max_size] = 7M
php_admin_value[max_file_uploads] = 1
EOF
sudo chown root:root /etc/php/8.5/fpm/pool.d/emuranga.conf
sudo chmod 0644 /etc/php/8.5/fpm/pool.d/emuranga.conf
sudo php-fpm8.5 -t
sudo systemctl restart php8.5-fpm
sudo systemctl is-active php8.5-fpm
```

### 7. Add a separate Nginx virtual host

```bash
sudo tee /etc/nginx/sites-available/emuranga >/dev/null <<'EOF'
server {
    listen 80;
    server_name emuranga.korebench.co.ke;
    root /home/alec/apps/e-muranga;
    index index.php;
    client_max_body_size 7m;

    location ^~ /var/ {
        deny all;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~* ^/(README\.md|schema\.sql|bin/) {
        deny all;
    }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include snippets/fastcgi-php.conf;
        fastcgi_param HTTPS $https if_not_empty;
        fastcgi_pass unix:/run/php/php8.5-fpm-emuranga.sock;
    }
}
EOF

sudo ln -s /etc/nginx/sites-available/emuranga /etc/nginx/sites-enabled/emuranga
sudo nginx -t
sudo systemctl reload nginx
curl -sS -o /dev/null -w 'HTTP %{http_code}\n' -H 'Host: emuranga.korebench.co.ke' http://127.0.0.1/
```

The first HTTP response should redirect signed-out visitors to the login page. If Nginx says the symlink already exists, inspect it instead of overwriting it.

### 8. Enable HTTPS

After DNS resolves to this server and inbound ports 80/443 are allowed:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d emuranga.korebench.co.ke
sudo nginx -t
```

Once HTTPS works, Cloudflare users can turn on proxying and set SSL/TLS mode to **Full (strict)**.

## Manual acceptance checklist

1. Register a citizen and sign in; a new citizen cannot open `admin.php`.
2. Submit reports in two categories with location and details; submit a third with a JPG/PNG/WebP photo.
3. Sign out and sign in as the staff admin. Confirm the reports, photo, category counts and open/resolved totals are visible.
4. Advance a case from Pending to In Progress and then Resolved. Check that the audit trail is updated and the citizen sees the new status.
5. Confirm one citizen cannot see another citizen's private report photo.

**Demo limitation:** No email verification, password reset, rate-limiting service, public-data imports, or real county integration is included. Use sample data, not real citizen personal information.
