<?php
declare(strict_types=1);

require __DIR__ . '/daraja.php';
header('Content-Type: application/json; charset=utf-8');

$configuredToken = (string) (getenv('EMURANGA_DARAJA_CALLBACK_TOKEN') ?: '');
$receivedToken = (string) ($_GET['token'] ?? '');
if (strlen($configuredToken) < 32 || !hash_equals($configuredToken, $receivedToken)) {
    http_response_code(404);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Not found']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'POST required']);
    exit;
}

try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']);
    exit;
}
$callback = is_array($payload) && is_array($payload['Body'] ?? null)
    ? ($payload['Body']['stkCallback'] ?? null)
    : null;
$checkoutId = is_array($callback) ? ($callback['CheckoutRequestID'] ?? null) : null;
$resultCode = is_array($callback) ? ($callback['ResultCode'] ?? null) : null;
if (!is_string($checkoutId) || $checkoutId === '' || !is_numeric($resultCode)) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback']);
    exit;
}

try {
    $pdo = database();
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT * FROM parking_payments WHERE checkout_request_id = :checkout_request_id FOR UPDATE');
    $statement->execute(['checkout_request_id' => $checkoutId]);
    $payment = $statement->fetch();
    if (!$payment) {
        $pdo->commit();
        error_log('eMuranga received a Daraja callback for an unknown checkout request.');
    } elseif ($payment['status'] !== 'pending') {
        $pdo->commit();
    } elseif ((int) $resultCode !== 0) {
        $statement = $pdo->prepare(
            "UPDATE parking_payments
             SET status = 'failed', result_code = :result_code,
                 result_description = 'M-Pesa payment was not completed.'
             WHERE id = :id AND status = 'pending'"
        );
        $statement->execute(['result_code' => (int) $resultCode, 'id' => $payment['id']]);
        $pdo->commit();
    } else {
        $items = $callback['CallbackMetadata']['Item'] ?? null;
        $metadata = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item) && is_string($item['Name'] ?? null) && array_key_exists('Value', $item)) {
                    $metadata[$item['Name']] = $item['Value'];
                }
            }
        }
        $paidPhone = is_numeric($metadata['PhoneNumber'] ?? null)
            ? (string) $metadata['PhoneNumber']
            : '';
        $receipt = $metadata['MpesaReceiptNumber'] ?? null;
        $paidAmount = $metadata['Amount'] ?? null;
        if (
            !is_string($receipt) || $receipt === ''
            || strlen($receipt) > 30
            || !is_numeric($paidAmount)
            || (int) $paidAmount !== (int) $payment['amount']
            || normalizeKenyanPhone($paidPhone) !== $payment['phone']
        ) {
            $statement = $pdo->prepare(
                "UPDATE parking_payments
                 SET status = 'review', result_code = 0,
                     result_description = 'Payment details did not match the pending request.'
                 WHERE id = :id AND status = 'pending'"
            );
            $statement->execute(['id' => $payment['id']]);
            $pdo->commit();
            error_log('eMuranga marked a parking payment for review because callback details did not match.');
        } else {
            $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:vehicle_registration))')
                ->execute(['vehicle_registration' => $payment['vehicle_registration']]);
            $statement = $pdo->prepare(
                "SELECT GREATEST(CURRENT_TIMESTAMP, COALESCE(MAX(valid_until), CURRENT_TIMESTAMP))
                 FROM parking_payments
                 WHERE vehicle_registration = :vehicle_registration AND status = 'paid'"
            );
            $statement->execute(['vehicle_registration' => $payment['vehicle_registration']]);
            $validFrom = $statement->fetchColumn();

            $statement = $pdo->prepare(
                "UPDATE parking_payments
                 SET status = 'paid', result_code = 0, mpesa_receipt_number = :receipt,
                     result_description = 'M-Pesa payment confirmed.', paid_at = CURRENT_TIMESTAMP,
                     valid_from = CAST(:valid_from AS TIMESTAMPTZ),
                     valid_until = CAST(:valid_until_from AS TIMESTAMPTZ) + INTERVAL '1 month'
                 WHERE id = :id AND status = 'pending'"
            );
            $statement->execute([
                'receipt' => $receipt,
                'valid_from' => $validFrom,
                'valid_until_from' => $validFrom,
                'id' => $payment['id'],
            ]);
            $pdo->commit();
        }
    }

    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('eMuranga Daraja callback processing error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Processing failed']);
}
