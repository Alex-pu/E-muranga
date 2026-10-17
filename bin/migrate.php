<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config.php';

try {
    migrateDatabase(database());
    fwrite(STDOUT, "eMuranga database migration completed.\n");
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Database migration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
