<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();

header('Location: ' . (isset($_SESSION['user']) ? 'dashboard.php' : 'auth.php'));
exit;
