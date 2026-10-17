# eMuranga service desk demo

A small PHP and PostgreSQL account portal for a Murang'a County internship demo. Visitors can sign up, log in, choose from six county-service concepts, submit requests and see their own request history. This is an independent project concept, not an official county or eCitizen website.

## Deploy on a Linux server

Requirements: PHP 8.1+ with `pdo_pgsql` enabled, Git, and a Neon PostgreSQL database.

1. Install PHP and the PostgreSQL driver. On Ubuntu/Debian:

   ```bash
   sudo apt update
   sudo apt install git php php-pgsql
   ```

2. Clone the app:

   ```bash
   cd /var/www
   sudo git clone https://github.com/Alex-pu/E-muranga.git
   cd E-muranga
   ```

3. For a quick local test, set the **rotated** Neon URL in the shell and start PHP:

   ```bash
   export DATABASE_URL='postgresql://USER:PASSWORD@HOST/DATABASE?sslmode=require'
   php -S 127.0.0.1:8000
   ```

   Open `http://127.0.0.1:8000` on the server, or use an SSH tunnel to view it from your computer. The PHP built-in server is for testing only. For a public site, configure Apache or Nginx with PHP-FPM and HTTPS, and set `DATABASE_URL` in the server configuration, not in this repository.

The app initializes the account and request tables on first use. The database user needs permission to create and alter tables and create an index. Alternatively, run `schema.sql` in Neon before opening the site.

Keep the Neon password out of Git. It was shared in chat, so rotate it before using the database.

**Demo only:** Use sample details, not real citizen ID, health, or payment information. This is not an official county system and is not ready for public deployment.
