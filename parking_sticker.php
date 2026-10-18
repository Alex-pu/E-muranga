<?php
declare(strict_types=1);

function parkingStickerPdf(array $sticker, string $accountHolder): string
{
    $commands = [];
    $commands[] = '0.07 0.24 0.19 rg 0 700 595 142 re f';
    $commands[] = 'BT /F1 10 Tf 1 1 1 rg 52 805 Td (eMuranga  |  MONTHLY PARKING STICKER) Tj ET';
    $commands[] = 'BT /F1 9 Tf 0.83 0.91 0.85 rg 52 784 Td (Payment-confirmed parking permit) Tj ET';

    $isCurrent = strtotime($sticker['valid_until']) > time();
    $status = $isCurrent ? 'PAID - VALID' : 'EXPIRED';
    $commands[] = $isCurrent
        ? '0.91 0.96 0.91 rg 52 737 491 31 re f'
        : '0.94 0.94 0.93 rg 52 737 491 31 re f';
    $commands[] = 'BT /F1 11 Tf 0.15 0.38 0.23 rg 66 748 Td (' . pdfLiteral($status) . ') Tj ET';
    $validDates = date('M j, Y', strtotime($sticker['valid_from']))
        . ' - ' . date('M j, Y', strtotime($sticker['valid_until']));
    $commands[] = 'BT /F1 10 Tf 0.12 0.20 0.16 rg 283 748 Td (' . pdfLiteral($validDates) . ') Tj ET';

    $commands[] = '0.96 0.97 0.96 rg 52 642 491 73 re f';
    $commands[] = 'BT /F1 9 Tf 0.38 0.45 0.40 rg 68 692 Td (VEHICLE REGISTRATION) Tj ET';
    $commands[] = 'BT /F1 26 Tf 0.07 0.24 0.19 rg 68 659 Td (' . pdfLiteral($sticker['vehicle_registration']) . ') Tj ET';

    $detailRows = [
        ['PARKING STAGE', $sticker['parking_stage']],
        ['WARD', $sticker['ward']],
        ['PAID', 'KSh ' . number_format((int) $sticker['amount'])],
        ['M-PESA RECEIPT', (string) $sticker['mpesa_receipt_number']],
        ['ACCOUNT HOLDER', $accountHolder],
    ];
    $y = 606;
    foreach ($detailRows as [$label, $value]) {
        $commands[] = 'BT /F1 9 Tf 0.40 0.47 0.42 rg 68 ' . $y . ' Td (' . pdfLiteral($label) . ') Tj ET';
        $pdfValue = pdfEncodedText((string) $value);
        $valueLines = explode("\n", wordwrap($pdfValue, 43, "\n", true));
        foreach ($valueLines as $lineIndex => $line) {
            $lineY = $y - ($lineIndex * 12);
            $commands[] = 'BT /F1 11 Tf 0.12 0.20 0.16 rg 220 ' . $lineY . ' Td (' . pdfEscape($line) . ') Tj ET';
        }
        $rowHeight = max(40, count($valueLines) * 14 + 12);
        $y -= $rowHeight;
        $commands[] = '0.89 0.92 0.89 RG 68 ' . ($y + 10) . ' m 527 ' . ($y + 10) . ' l S';
    }
    $commands[] = 'BT /F1 9 Tf 0.38 0.45 0.40 rg 68 325 Td (Keep this sticker visible in the vehicle. Verify payment with the receipt above.) Tj ET';
    $commands[] = 'BT /F1 9 Tf 0.48 0.53 0.49 rg 68 50 Td (eMuranga demo - Not an official county parking permit) Tj ET';

    $content = implode("\n", $commands) . "\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

    return $pdf;
}

function pdfLiteral(string $value): string
{
    return pdfEscape(pdfEncodedText($value));
}

function pdfEncodedText(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';
    $encoded = function_exists('iconv') ? iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value) : false;
    if ($encoded === false) {
        $encoded = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
    }

    return $encoded;
}

function pdfEscape(string $encodedValue): string
{
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encodedValue);
}

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
        "SELECT vehicle_registration, parking_stage, ward, amount, mpesa_receipt_number, paid_at, valid_from, valid_until
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
if (($_GET['download'] ?? '') === '1') {
    $pdf = parkingStickerPdf($sticker, $user['full_name']);
    $registration = preg_replace('/[^A-Z0-9-]/', '', $sticker['vehicle_registration']) ?: 'vehicle';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="parking-sticker-' . $registration . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123d31">
    <title>Parking sticker | eMuranga</title>
    <link rel="stylesheet" href="style.css?v=20261018-3">
</head>
<body class="sticker-page">
<main class="sticker-wrap">
    <div class="sticker-actions">
        <a class="text-link" href="dashboard.php#parking">← Back to parking</a>
        <div class="sticker-button-group">
            <a class="text-link" href="parking_sticker.php?id=<?= (int) $paymentId ?>&amp;download=1" download>Download PDF</a>
            <button class="primary-button" type="button" onclick="window.print()">Print sticker</button>
        </div>
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
            <div><dt>Parking stage</dt><dd><?= escape($sticker['parking_stage']) ?></dd></div>
            <div><dt>Ward</dt><dd><?= escape($sticker['ward']) ?></dd></div>
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
