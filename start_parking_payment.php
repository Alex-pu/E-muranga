<?php
declare(strict_types=1);

require __DIR__ . '/daraja.php';
startAppSession();
$user = requireLogin();
if (($user['role'] ?? 'citizen') === 'admin') {
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php#parking');
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    redirectWithMessage('error', 'Your form session expired. Please refresh the page and try again.', 'dashboard.php#parking');
}

$registration = strtoupper(trim((string) ($_POST['vehicle_registration'] ?? '')));
$registration = preg_replace('/\s+/', ' ', $registration) ?? '';
$parkingStage = trim((string) ($_POST['parking_stage'] ?? ''));
$parkingStage = preg_replace('/\s+/u', ' ', $parkingStage) ?? '';
$ward = trim((string) ($_POST['ward'] ?? ''));
$ward = preg_replace('/\s+/u', ' ', $ward) ?? '';
$phone = normalizeKenyanPhone(trim((string) ($_POST['phone'] ?? '')));
if (
    !preg_match('/\A(?=.*[A-Z])(?=.*\d)[A-Z0-9 -]{3,12}\z/', $registration)
    || $parkingStage === ''
    || preg_match('//u', $parkingStage) !== 1
    || preg_match('/[\x00-\x1F\x7F]/', $parkingStage)
    || strlen($parkingStage) > 100
    || $ward === ''
    || preg_match('//u', $ward) !== 1
    || preg_match('/[\x00-\x1F\x7F]/', $ward)
    || strlen($ward) > 100
    || $phone === null
) {
    redirectWithMessage('error', 'Enter the vehicle registration, parking stage, ward and a valid Kenyan M-Pesa phone number.', 'dashboard.php#parking');
}

$paymentId = null;
try {
    $config = darajaConfiguration();
    $amount = parkingMonthlyFee();
    $pdo = database();
    $statement = $pdo->prepare(
        "INSERT INTO parking_payments (user_id, vehicle_registration, parking_stage, ward, phone, amount)
         VALUES (:user_id, :vehicle_registration, :parking_stage, :ward, :phone, :amount)
         RETURNING id"
    );
    $statement->execute([
        'user_id' => $user['id'],
        'vehicle_registration' => $registration,
        'parking_stage' => $parkingStage,
        'ward' => $ward,
        'phone' => $phone,
        'amount' => $amount,
    ]);
    $paymentId = (int) $statement->fetchColumn();

    $response = darajaStartStkPush($config, $phone, $registration, $amount);
    $statement = $pdo->prepare(
        "UPDATE parking_payments
         SET merchant_request_id = :merchant_request_id,
             checkout_request_id = :checkout_request_id
         WHERE id = :id AND status = 'pending'"
    );
    $statement->execute([
        'merchant_request_id' => $response['MerchantRequestID'],
        'checkout_request_id' => $response['CheckoutRequestID'],
        'id' => $paymentId,
    ]);

    redirectWithMessage(
        'success',
        'M-Pesa prompt sent to ' . $phone . '. Enter your PIN, then refresh this page to see your payment status and sticker.',
        'dashboard.php#parking'
    );
} catch (RuntimeException $exception) {
    if ($paymentId !== null && isset($pdo) && $pdo instanceof PDO) {
        try {
            $statement = $pdo->prepare(
                "UPDATE parking_payments
                 SET status = 'review', result_description = 'The M-Pesa request outcome could not be confirmed.'
                 WHERE id = :id AND status = 'pending' AND checkout_request_id IS NULL"
            );
            $statement->execute(['id' => $paymentId]);
        } catch (PDOException $updateException) {
            error_log('eMuranga could not update a failed parking payment request: ' . $updateException->getMessage());
        }
    }
    error_log('eMuranga parking payment request error: ' . $exception->getMessage());
    $failureMessage = $paymentId === null
        ? 'We could not start the M-Pesa payment. Check the Daraja configuration and try again.'
        : 'We could not confirm whether the M-Pesa request reached Safaricom. Check your M-Pesa messages and payment history; contact the service desk before trying again.';
    redirectWithMessage(
        'error',
        $failureMessage,
        'dashboard.php#parking'
    );
}
