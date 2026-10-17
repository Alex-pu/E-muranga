<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();
$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php');
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    redirectWithMessage('error', 'Your form session expired. Please try again.', 'admin.php#cases');
}

$requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$nextStatus = (string) ($_POST['status'] ?? '');
if ($requestId === false || !in_array($nextStatus, ['In Progress', 'Resolved'], true)) {
    redirectWithMessage('error', 'That status update is not valid.', 'admin.php#cases');
}

try {
    $pdo = database();
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT status FROM service_requests WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $requestId]);
    $request = $statement->fetch();
    $allowedNext = ['Pending' => 'In Progress', 'In Progress' => 'Resolved'];
    if (!$request || ($allowedNext[$request['status']] ?? null) !== $nextStatus) {
        $pdo->rollBack();
        redirectWithMessage('error', 'This case has already changed or cannot move to that status.', 'admin.php#cases');
    }

    $statement = $pdo->prepare(
        'UPDATE service_requests SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $statement->execute(['status' => $nextStatus, 'id' => $requestId]);
    $statement = $pdo->prepare(
        'INSERT INTO request_status_history (request_id, changed_by, old_status, new_status)
         VALUES (:request_id, :changed_by, :old_status, :new_status)'
    );
    $statement->execute([
        'request_id' => $requestId,
        'changed_by' => $admin['id'],
        'old_status' => $request['status'],
        'new_status' => $nextStatus,
    ]);
    $pdo->commit();
    redirectWithMessage('success', 'Case status updated to ' . $nextStatus . '.', 'admin.php#cases');
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('eMuranga admin status update error: ' . $exception->getMessage());
    redirectWithMessage('error', 'We could not update this case. Check the database and try again.', 'admin.php#cases');
}
