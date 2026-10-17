<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config.php';

$email = strtolower(trim((string) ($argv[1] ?? '')));
$password = getenv('ADMIN_PASSWORD');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === false || strlen($password) < 12) {
    fwrite(STDERR, "Set ADMIN_PASSWORD through a hidden prompt, then run: php bin/create-admin.php admin@example.org\n");
    fwrite(STDERR, "Do not pass the password as a command-line argument.\n");
    exit(1);
}

try {
    $pdo = database();
    $statement = $pdo->prepare(
        "INSERT INTO app_users (full_name, email, phone, password_hash, role)
         VALUES (:full_name, :email, :phone, :password_hash, 'admin')
         ON CONFLICT (email) DO UPDATE
         SET full_name = EXCLUDED.full_name,
             password_hash = EXCLUDED.password_hash,
             role = 'admin'
         RETURNING email"
    );
    $statement->execute([
        'full_name' => 'Service Desk Administrator',
        'email' => $email,
        'phone' => 'Staff account',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    printf("Administrator account ready for %s\n", $statement->fetchColumn());
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Could not create administrator account: ' . $exception->getMessage() . "\n");
    exit(1);
}
