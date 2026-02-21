<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$login = getenv('DFS_LOGIN');
$password = getenv('DFS_PASSWORD');

if ($login === false || $password === false || $login === '' || $password === '') {
    echo "Error: Missing required environment variables DFS_LOGIN and/or DFS_PASSWORD.\n";
    exit(1);
}

$url = 'https://api.dataforseo.com/v3/dataforseo_labs/google/keyword_suggestions/live';

$payload = [
    [
        'keyword' => 'seo',
        'location_code' => 2840,
        'language_code' => 'en',
    ],
];

$jsonPayload = json_encode($payload);
if ($jsonPayload === false) {
    echo "=== HTTP STATUS ===\n";
    echo "N/A\n\n";
    echo "=== RESPONSE BODY ===\n";
    echo "N/A\n\n";
    echo "=== CURL ERROR ===\n";
    echo 'Failed to encode JSON payload: ' . json_last_error_msg() . "\n";
    exit(1);
}

$authHeader = 'Authorization: Basic ' . base64_encode($login . ':' . $password);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        $authHeader,
    ],
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_TIMEOUT => 30,
]);

$responseBody = curl_exec($ch);
$curlError = '';

if ($responseBody === false) {
    $curlError = curl_error($ch);
    $responseBody = '';
}

$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "=== HTTP STATUS ===\n";
echo $httpStatus . "\n\n";

echo "=== RESPONSE BODY ===\n";
echo $responseBody . "\n\n";

echo "=== CURL ERROR ===\n";
echo ($curlError !== '' ? $curlError : 'None') . "\n";
