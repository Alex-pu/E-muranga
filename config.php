<?php
declare(strict_types=1);

function loadAppEnvironment(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $loaded = true;
    $path = getenv('EMURANGA_ENV_FILE') ?: '/etc/emuranga/app.env';
    if (!is_file($path)) {
        return;
    }
    if (!is_readable($path)) {
        throw new RuntimeException('The private application environment file is not readable.');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('The private application environment file could not be read.');
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/\A([A-Z][A-Z0-9_]*)=(.*)\z/D', $line, $matches)) {
            throw new RuntimeException('The private application environment file has an invalid line.');
        }
        if (getenv($matches[1]) === false) {
            putenv($matches[1] . '=' . $matches[2]);
            $_ENV[$matches[1]] = $matches[2];
        }
    }
}

loadAppEnvironment();

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}

function requireLogin(): array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || !isset($user['id'], $user['full_name'], $user['email'], $user['phone'])) {
        header('Location: auth.php');
        exit;
    }

    return $user;
}

function requireAdmin(): array
{
    $user = requireLogin();
    if (($user['role'] ?? 'citizen') !== 'admin') {
        http_response_code(403);
        exit('This page is for service-desk administrators.');
    }

    return $user;
}

function redirectWithMessage(string $type, string $message, string $location = 'index.php'): never
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $location);
    exit;
}

function database(): PDO
{
    $host = getenv('PGHOST');
    $port = getenv('PGPORT') ?: '5432';
    $name = getenv('PGDATABASE');
    $username = getenv('PGUSER');
    $password = getenv('PGPASSWORD');

    if (
        $host === false || $host === '' || str_contains($host, ';')
        || $name === false || $name === '' || str_contains($name, ';')
        || $username === false || $username === ''
        || $password === false || $password === ''
        || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false
    ) {
        throw new RuntimeException('Configure PGHOST, PGPORT, PGDATABASE, PGUSER and PGPASSWORD in the private app environment file.');
    }

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function migrateDatabase(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_users (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            full_name VARCHAR(120) NOT NULL,
            email VARCHAR(254) NOT NULL UNIQUE,
            phone VARCHAR(30) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT \'citizen\' CHECK (role IN (\'citizen\', \'admin\')),
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS service_requests (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            reference_code VARCHAR(20) NOT NULL UNIQUE,
            user_id BIGINT REFERENCES app_users(id) ON DELETE CASCADE,
            full_name VARCHAR(120) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            service_name VARCHAR(100) NOT NULL DEFAULT \'\',
            category VARCHAR(80) NOT NULL DEFAULT \'Other\',
            location VARCHAR(200) NOT NULL DEFAULT \'\',
            description TEXT NOT NULL DEFAULT \'\',
            image_path VARCHAR(255),
            notes TEXT NOT NULL DEFAULT \'\',
            status VARCHAR(20) NOT NULL DEFAULT \'Pending\' CHECK (status IN (\'Pending\', \'In Progress\', \'Resolved\')),
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec("ALTER TABLE app_users ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'citizen'");
    $pdo->exec('ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS user_id BIGINT REFERENCES app_users(id) ON DELETE CASCADE');
    $pdo->exec("ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS national_id VARCHAR(30) NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE service_requests ALTER COLUMN national_id SET DEFAULT ''");
    $pdo->exec("ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS category VARCHAR(80) NOT NULL DEFAULT 'Other'");
    $pdo->exec("ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS location VARCHAR(200) NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS description TEXT NOT NULL DEFAULT ''");
    $pdo->exec('ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS image_path VARCHAR(255)');
    $pdo->exec("ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS service_name VARCHAR(100) NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS notes TEXT NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $pdo->exec("UPDATE service_requests SET category = service_name WHERE category = 'Other' AND service_name <> ''");
    $pdo->exec("UPDATE service_requests SET description = notes WHERE description = '' AND notes <> ''");
    $pdo->exec("UPDATE service_requests SET status = CASE status WHEN 'Received' THEN 'Pending' WHEN 'In review' THEN 'In Progress' WHEN 'Completed' THEN 'Resolved' ELSE status END WHERE status IN ('Received', 'In review', 'Completed')");
    $pdo->exec("ALTER TABLE service_requests ALTER COLUMN status SET DEFAULT 'Pending'");
    $pdo->exec('CREATE INDEX IF NOT EXISTS service_requests_user_created_idx ON service_requests (user_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS service_requests_status_created_idx ON service_requests (status, created_at DESC)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS request_status_history (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            request_id BIGINT NOT NULL REFERENCES service_requests(id) ON DELETE CASCADE,
            changed_by BIGINT REFERENCES app_users(id) ON DELETE SET NULL,
            old_status VARCHAR(20),
            new_status VARCHAR(20) NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS parking_payments (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id BIGINT NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
            vehicle_registration VARCHAR(12) NOT NULL,
            parking_stage VARCHAR(100) NOT NULL DEFAULT \'\',
            ward VARCHAR(100) NOT NULL DEFAULT \'\',
            phone VARCHAR(12) NOT NULL,
            amount INTEGER NOT NULL CHECK (amount > 0),
            status VARCHAR(20) NOT NULL DEFAULT \'pending\'
                CHECK (status IN (\'pending\', \'paid\', \'failed\', \'review\')),
            merchant_request_id VARCHAR(100),
            checkout_request_id VARCHAR(100) UNIQUE,
            mpesa_receipt_number VARCHAR(30) UNIQUE,
            result_code INTEGER,
            result_description VARCHAR(255) NOT NULL DEFAULT \'\',
            paid_at TIMESTAMPTZ,
            valid_from TIMESTAMPTZ,
            valid_until TIMESTAMPTZ,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec("ALTER TABLE parking_payments ADD COLUMN IF NOT EXISTS parking_stage VARCHAR(100) NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE parking_payments ADD COLUMN IF NOT EXISTS ward VARCHAR(100) NOT NULL DEFAULT ''");
    $pdo->exec('CREATE INDEX IF NOT EXISTS parking_payments_user_created_idx ON parking_payments (user_id, created_at DESC)');
    $pdo->exec("CREATE INDEX IF NOT EXISTS parking_payments_vehicle_valid_idx ON parking_payments (vehicle_registration, valid_until DESC) WHERE status = 'paid'");
}

function requestCategories(): array
{
    return array_keys(requestCategoryDetails());
}

function requestCategoryDetails(): array
{
    return [
        'Streetlight' => ['icon' => '◉', 'description' => 'Broken lights and dark streets.'],
        'Water supply' => ['icon' => '≈', 'description' => 'Leaks, interruptions and water quality.'],
        'Garbage collection' => ['icon' => '♲', 'description' => 'Missed collections and dumping.'],
        'Roads and drainage' => ['icon' => '⌁', 'description' => 'Potholes, blocked drains and flooding.'],
        'Public health' => ['icon' => '✚', 'description' => 'Sanitation and public-space concerns.'],
        'Other' => ['icon' => '＋', 'description' => 'Something else in your community.'],
    ];
}

function requestUploadDirectory(): string
{
    $directory = getenv('UPLOAD_DIR')
        ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'emuranga-uploads-' . substr(hash('sha256', __DIR__), 0, 12);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the request upload directory.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('The request upload directory is not writable by PHP.');
    }

    return $directory;
}

function requestStatusLabel(string $status): string
{
    return in_array($status, ['Pending', 'In Progress', 'Resolved'], true) ? $status : 'Pending';
}
