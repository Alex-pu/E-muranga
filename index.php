<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();

if (isset($_SESSION['user'])) {
    header('Location: ' . (($_SESSION['user']['role'] ?? 'citizen') === 'admin' ? 'admin.php' : 'dashboard.php'));
} else {
    header('Location: auth.php');
}
exit;
