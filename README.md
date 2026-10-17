# eMuranga service desk demo

A small PHP and PostgreSQL account portal for a Murang'a County internship demo. Visitors can sign up, log in, choose from six county-service concepts, submit requests and see their own request history. This is an independent project concept, not an official county or eCitizen website.

## Run locally

Requirements: PHP 8.1+ with `pdo_pgsql` enabled, and a PostgreSQL database (Neon works).

1. In PowerShell, set your Neon connection URL for the current terminal. Paste the **rotated** Neon URL between the quotes; don't put the database password in a source file:

   ```powershell
   $env:DATABASE_URL = "postgresql://USER:PASSWORD@HOST/DATABASE?sslmode=require"
   ```

2. From this folder, start PHP's built-in server:

   ```powershell
   php -S localhost:8000
   ```

3. Open <http://localhost:8000> and create a demo account.

The app creates the account and service-request tables on first use and adds the account link to an older demo `service_requests` table. The database user needs permission to create and alter tables. Alternatively, run `schema.sql` using a database user with those permissions.

Set `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER` and `PGPASSWORD` instead if your host does not provide `DATABASE_URL`.

**Demo only:** Use sample details, not real citizen ID, health or payment information. This is not an official county system and is not ready for public deployment.
