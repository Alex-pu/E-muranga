<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    redirectWithMessage('error', 'Your form session expired. Please refresh the page and try again.', 'dashboard.php#new-request');
}

$phone = trim((string) ($_POST['phone'] ?? $user['phone']));
$service = trim((string) ($_POST['service_name'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$allowedServices = [
    'Land rates payment',
    'Business permit',
    'Health services',
    'Birth notification',
    'Agriculture support',
    'Report an issue',
];

if ($phone === '' || !in_array($service, $allowedServices, true)) {
    redirectWithMessage('error', 'Please enter a contact number and select a valid service.', 'dashboard.php#new-request');
}
if (strlen($phone) > 30 || strlen($notes) > 2000) {
    redirectWithMessage('error', 'One or more fields are too long. Please check your details and try again.', 'dashboard.php#new-request');
}

try {
    $pdo = database();
    ensureRequestTable($pdo);
    $reference = 'MC-' . strtoupper(bin2hex(random_bytes(4)));
    $statement = $pdo->prepare(
        'INSERT INTO service_requests (reference_code, user_id, full_name, phone, service_name, notes)
         VALUES (:reference, :user_id, :full_name, :phone, :service_name, :notes)'
    );
    $statement->execute([
        'reference' => $reference,
        'user_id' => $user['id'],
        'full_name' => $user['full_name'],
        'phone' => $phone,
        'service_name' => $service,
        'notes' => $notes,
    ]);
    redirectWithMessage('success', 'Request received! Your reference number is ' . $reference . '.', 'dashboard.php#requests');
} catch (PDOException $exception) {
    error_log('eMuranga request submission error: ' . $exception->getMessage());
    redirectWithMessage('error', 'We could not submit your request right now. Please check the database connection and try again.', 'dashboard.php#new-request');
} catch (RuntimeException $exception) {
    error_log('eMuranga request submission error: ' . $exception->getMessage());
    redirectWithMessage('error', 'We could not submit your request right now. Please check the database connection and try again.', 'dashboard.php#new-request');
}
