<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    redirectWithMessage('error', 'Your form session expired. Please try again.', 'dashboard.php');
}

if (($_POST['action'] ?? '') !== 'logout') {
    redirectWithMessage('error', 'That action is not available.', 'dashboard.php');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
}
session_destroy();
header('Location: auth.php');
exit;
