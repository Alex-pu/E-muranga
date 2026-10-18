<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function darajaConfiguration(): array
{
    $environment = getenv('EMURANGA_DARAJA_ENV') ?: 'sandbox';
    $publicUrl = rtrim((string) (getenv('EMURANGA_PUBLIC_URL') ?: ''), '/');
    $callbackToken = (string) (getenv('EMURANGA_DARAJA_CALLBACK_TOKEN') ?: '');
    $shortcode = (string) (getenv('EMURANGA_DARAJA_SHORTCODE') ?: '');

    if (!in_array($environment, ['sandbox', 'production'], true)) {
        throw new RuntimeException('EMURANGA_DARAJA_ENV must be sandbox or production.');
    }
    if (
        getenv('EMURANGA_DARAJA_CONSUMER_KEY') === false
        || getenv('EMURANGA_DARAJA_CONSUMER_KEY') === ''
        || getenv('EMURANGA_DARAJA_CONSUMER_SECRET') === false
        || getenv('EMURANGA_DARAJA_CONSUMER_SECRET') === ''
        || getenv('EMURANGA_DARAJA_PASSKEY') === false
        || getenv('EMURANGA_DARAJA_PASSKEY') === ''
    ) {
        throw new RuntimeException('Daraja consumer credentials and passkey must be configured.');
    }
    if ($shortcode === '' || !ctype_digit($shortcode)) {
        throw new RuntimeException('EMURANGA_DARAJA_SHORTCODE must contain digits only.');
    }
    if (strlen($callbackToken) < 32) {
        throw new RuntimeException('EMURANGA_DARAJA_CALLBACK_TOKEN must contain at least 32 characters.');
    }
    $publicUrlParts = parse_url($publicUrl);
    if (
        !is_array($publicUrlParts)
        || ($publicUrlParts['scheme'] ?? '') !== 'https'
        || empty($publicUrlParts['host'])
        || isset($publicUrlParts['query'])
        || isset($publicUrlParts['fragment'])
    ) {
        throw new RuntimeException('EMURANGA_PUBLIC_URL must be a public HTTPS base URL without a query or fragment.');
    }

    return [
        'environment' => $environment,
        'consumer_key' => (string) getenv('EMURANGA_DARAJA_CONSUMER_KEY'),
        'consumer_secret' => (string) getenv('EMURANGA_DARAJA_CONSUMER_SECRET'),
        'shortcode' => $shortcode,
        'passkey' => (string) getenv('EMURANGA_DARAJA_PASSKEY'),
        'callback_url' => $publicUrl . '/daraja_callback.php?token=' . rawurlencode($callbackToken),
        'callback_token' => $callbackToken,
    ];
}

function darajaIsConfigured(): bool
{
    try {
        darajaConfiguration();
        return true;
    } catch (RuntimeException) {
        return false;
    }
}

function parkingMonthlyFee(): int
{
    $configuredFee = getenv('EMURANGA_PARKING_MONTHLY_FEE');
    if ($configuredFee === false || $configuredFee === '') {
        $configuredFee = '1';
    }
    $fee = filter_var($configuredFee, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
    if ($fee === false) {
        throw new RuntimeException('EMURANGA_PARKING_MONTHLY_FEE must be a whole number from 1 to 1000000.');
    }

    return $fee;
}

function normalizeKenyanPhone(string $phone): ?string
{
    $digits = preg_replace('/[\s()+-]/', '', $phone);
    if (!is_string($digits)) {
        return null;
    }
    if (preg_match('/\A0([17]\d{8})\z/', $digits, $matches)) {
        return '254' . $matches[1];
    }
    if (preg_match('/\A254[17]\d{8}\z/', $digits)) {
        return $digits;
    }

    return null;
}

function darajaApiRequest(string $url, array $headers, ?array $payload = null, ?array $basicAuth = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Daraja payments.');
    }
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Could not initialize the Daraja connection.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS_STR => 'https',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    if ($basicAuth !== null) {
        $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $options[CURLOPT_USERPWD] = $basicAuth[0] . ':' . $basicAuth[1];
    }
    if (!curl_setopt_array($handle, $options)) {
        curl_close($handle);
        throw new RuntimeException('Could not configure the Daraja connection.');
    }

    $body = curl_exec($handle);
    $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);
    if (!is_string($body)) {
        error_log('eMuranga Daraja transport error: ' . $curlError);
        throw new RuntimeException('Daraja could not be reached.');
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        error_log('eMuranga Daraja returned HTTP ' . $httpStatus . '.');
        throw new RuntimeException('Daraja rejected the request (HTTP ' . $httpStatus . ').');
    }

    try {
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('eMuranga Daraja returned an invalid JSON response.');
        throw new RuntimeException('Daraja returned an invalid response.', 0, $exception);
    }
    if (!is_array($response)) {
        throw new RuntimeException('Daraja returned an invalid response.');
    }

    return $response;
}

function darajaStartStkPush(array $config, string $phone, string $registration, int $amount): array
{
    $apiBase = $config['environment'] === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
    $tokenResponse = darajaApiRequest(
        $apiBase . '/oauth/v1/generate?grant_type=client_credentials',
        ['Accept: application/json'],
        null,
        [$config['consumer_key'], $config['consumer_secret']]
    );
    $accessToken = $tokenResponse['access_token'] ?? null;
    if (!is_string($accessToken) || $accessToken === '') {
        throw new RuntimeException('Daraja did not provide an access token.');
    }

    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('YmdHis');
    $accountReference = preg_replace('/[^A-Z0-9]/', '', $registration);
    if (!is_string($accountReference) || $accountReference === '') {
        throw new RuntimeException('The vehicle registration could not be used as a payment reference.');
    }
    $response = darajaApiRequest(
        $apiBase . '/mpesa/stkpush/v1/processrequest',
        ['Accept: application/json', 'Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        [
            'BusinessShortCode' => $config['shortcode'],
            'Password' => base64_encode($config['shortcode'] . $config['passkey'] . $timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $config['shortcode'],
            'PhoneNumber' => $phone,
            'CallBackURL' => $config['callback_url'],
            'AccountReference' => substr($accountReference, 0, 12),
            'TransactionDesc' => 'Parking fee',
        ]
    );

    if (
        (string) ($response['ResponseCode'] ?? '') !== '0'
        || !is_string($response['MerchantRequestID'] ?? null)
        || $response['MerchantRequestID'] === ''
        || strlen($response['MerchantRequestID']) > 100
        || !is_string($response['CheckoutRequestID'] ?? null)
        || $response['CheckoutRequestID'] === ''
        || strlen($response['CheckoutRequestID']) > 100
    ) {
        error_log('eMuranga Daraja STK Push was not accepted.');
        throw new RuntimeException('Daraja did not accept the M-Pesa payment request.');
    }

    return $response;
}
