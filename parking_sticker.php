<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();
$user = requireLogin();
$paymentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($paymentId === false) {
    http_response_code(404);
    exit('Parking sticker not found.');
}

try {
    $pdo = database();
    $statement = $pdo->prepare(
        "SELECT vehicle_registration, amount, mpesa_receipt_number, paid_at, valid_from, valid_until
         FROM parking_payments
         WHERE id = :id AND user_id = :user_id AND status = 'paid'"
    );
    $statement->execute(['id' => $paymentId, 'user_id' => $user['id']]);
    $sticker = $statement->fetch();
} catch (RuntimeException $exception) {
    error_log('eMuranga parking sticker lookup error: ' . $exception->getMessage());
    http_response_code(500);
    exit('The parking sticker could not be loaded. Please try again later.');
}

if (!$sticker) {
    http_response_code(404);
    exit('Parking sticker not found.');
}
$isCurrent = strtotime($sticker['valid_until']) > time();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123d31">
    <title>Parking sticker | eMuranga</title>
    <link rel="stylesheet" href="style.css?v=20261018-1">
</head>
<body class="sticker-page">
<main class="sticker-wrap">
    <div class="sticker-actions">
        <a class="text-link" href="dashboard.php#parking">← Back to parking</a>
        <button class="primary-button" type="button" onclick="window.print()">Print sticker</button>
    </div>
    <article class="parking-sticker">
        <header class="sticker-header">
            <img src="assets/logo.png" alt="Murang'a County Government">
            <div><span>eMuranga · PARKING</span><strong>MONTHLY PARKING STICKER</strong></div>
        </header>
        <div class="sticker-validity<?= $isCurrent ? '' : ' sticker-expired' ?>">
            <strong><?= $isCurrent ? 'PAID · VALID' : 'EXPIRED' ?></strong>
            <span><?= date('M j, Y', strtotime($sticker['valid_from'])) ?> – <?= date('M j, Y', strtotime($sticker['valid_until'])) ?></span>
        </div>
        <div class="sticker-vehicle">
            <small>VEHICLE REGISTRATION</small>
            <strong><?= escape($sticker['vehicle_registration']) ?></strong>
        </div>
        <dl class="sticker-details">
            <div><dt>Paid</dt><dd>KSh <?= number_format((int) $sticker['amount']) ?></dd></div>
            <div><dt>M-Pesa receipt</dt><dd><?= escape((string) $sticker['mpesa_receipt_number']) ?></dd></div>
            <div><dt>Account holder</dt><dd><?= escape($user['full_name']) ?></dd></div>
        </dl>
        <p class="sticker-note">Keep this sticker visible in the vehicle. Verify payment with the M-Pesa receipt above.</p>
        <footer>eMuranga demo · Not an official county parking permit</footer>
    </article>
</main>
</body>
</html>
