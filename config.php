<?php
declare(strict_types=1);

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

function redirectWithMessage(string $type, string $message, string $location = 'index.php'): never
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $location);
    exit;
}

function database(): PDO
{
    $url = getenv('DATABASE_URL');

    if ($url !== false && $url !== '') {
        $parts = parse_url($url);
        if (
            $parts === false
            || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)
            || !isset($parts['host'], $parts['path'])
        ) {
            throw new RuntimeException('DATABASE_URL is not a valid PostgreSQL connection URL.');
        }

        parse_str($parts['query'] ?? '', $query);
        $channelBinding = $query['channel_binding'] ?? null;
        if ($channelBinding !== null && !in_array($channelBinding, ['disable', 'prefer', 'require'], true)) {
            throw new RuntimeException('DATABASE_URL has an unsupported channel_binding value.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=require%s',
            $parts['host'],
            $parts['port'] ?? 5432,
            rawurldecode(ltrim($parts['path'], '/')),
            $channelBinding === null ? '' : ';channel_binding=' . $channelBinding
        );
        $username = isset($parts['user']) ? rawurldecode($parts['user']) : '';
        $password = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
    } else {
        $host = getenv('PGHOST') ?: 'localhost';
        $port = getenv('PGPORT') ?: '5432';
        $name = getenv('PGDATABASE') ?: 'ecitizen';
        $username = getenv('PGUSER') ?: 'postgres';
        $password = getenv('PGPASSWORD') ?: '';
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
    }

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function ensureRequestTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_users (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            full_name VARCHAR(120) NOT NULL,
            email VARCHAR(254) NOT NULL UNIQUE,
            phone VARCHAR(30) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
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
            service_name VARCHAR(100) NOT NULL,
            notes TEXT NOT NULL DEFAULT \'\',
            status VARCHAR(20) NOT NULL DEFAULT \'Received\',
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec('ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS user_id BIGINT REFERENCES app_users(id) ON DELETE CASCADE');
    $pdo->exec("ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS national_id VARCHAR(30) NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE service_requests ALTER COLUMN national_id SET DEFAULT ''");
    $pdo->exec('CREATE INDEX IF NOT EXISTS service_requests_user_created_idx ON service_requests (user_id, created_at DESC)');
}
