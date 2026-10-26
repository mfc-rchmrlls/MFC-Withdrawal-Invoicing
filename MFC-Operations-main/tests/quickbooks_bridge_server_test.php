<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/core/quickbooks_exports.php';
require_once __DIR__ . '/../backend/core/quickbooks_bridge_client.php';

function server_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

function server_test_request(string $url, string $body, string $requestId, string $operation, string $secret, ?string $timestamp = null, ?string $signature = null, string $contentType = 'application/json'): array
{
    $timestamp = $timestamp ?? (string)time();
    $signature = $signature ?? hash_hmac(
        'sha256',
        $timestamp . "\n" . $operation . "\n" . $requestId . "\n" . hash('sha256', $body),
        $secret
    );
    $headers = [];
    $response = '';
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $contentType,
            'X-MFC-Request-Id: ' . $requestId,
            'X-MFC-Timestamp: ' . $timestamp,
            'X-MFC-Signature: ' . $signature,
        ],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            if (preg_match('/^([^:]+):\s*(.*?)\s*$/', $line, $matches)) {
                $headers[strtolower($matches[1])] = $matches[2];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 65536) {
                return 0;
            }
            $response .= $chunk;
            return strlen($chunk);
        },
    ]);
    $result = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($handle);
    curl_close($handle);
    if ($result === false || $error !== 0) {
        throw new RuntimeException('The bridge HTTP test request failed.');
    }
    return ['status' => $status, 'body' => $response, 'headers' => $headers];
}

function server_test_verify_response(array $response, string $requestId, string $secret): bool
{
    $timestamp = $response['headers']['x-mfc-timestamp'] ?? '';
    $signature = $response['headers']['x-mfc-signature'] ?? '';
    if (!preg_match('/^\d{10}$/D', $timestamp) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
        return false;
    }
    $expected = hash_hmac(
        'sha256',
        $timestamp . "\nresponse\n" . $requestId . "\n" . $response['status'] . "\n" . hash('sha256', $response['body']),
        $secret
    );
    return hash_equals($expected, $signature);
}

if (!function_exists('curl_init') || !function_exists('proc_open')) {
    throw new RuntimeException('This test requires PHP cURL and proc_open.');
}

$compiler = 'C:\\Windows\\Microsoft.NET\\Framework64\\v4.0.30319\\csc.exe';
if (!is_file($compiler)) {
    throw new RuntimeException('The verified .NET Framework x64 compiler is unavailable.');
}
$tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mfc-qb-bridge-' . bin2hex(random_bytes(8));
if (!mkdir($tempDirectory, 0700) && !is_dir($tempDirectory)) {
    throw new RuntimeException('Could not create the isolated bridge test directory.');
}
$source = realpath(__DIR__ . '/../backend/quickbooks-bridge/Program.cs');
if (!is_string($source)) {
    throw new RuntimeException('The bridge source file is unavailable.');
}
$executable = $tempDirectory . DIRECTORY_SEPARATOR . 'QuickBooksReadOnlyBridge.exe';
$compile = proc_open(
    [$compiler, '/nologo', '/target:exe', '/platform:x64', '/out:' . $executable,
        '/reference:System.Web.Extensions.dll', '/reference:System.Xml.dll',
        '/reference:Microsoft.CSharp.dll', $source],
    [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $compilePipes,
    __DIR__,
    null
);
if (!is_resource($compile)) {
    throw new RuntimeException('Could not start bridge compilation.');
}
$compileOutput = stream_get_contents($compilePipes[1]) . stream_get_contents($compilePipes[2]);
foreach ($compilePipes as $pipe) {
    fclose($pipe);
}
$compileStatus = proc_close($compile);
if ($compileStatus !== 0) {
    throw new RuntimeException('Bridge compilation failed: ' . trim($compileOutput));
}

$secret = 'phase3c-bridge-http-test-secret-0123456789';
$previousSecret = getenv('WQ_QB_BRIDGE_SECRET');
$previousLocalAppData = getenv('LOCALAPPDATA');
putenv('WQ_QB_BRIDGE_SECRET=' . $secret);
putenv('LOCALAPPDATA=' . $tempDirectory);
$process = proc_open(
    [$executable],
    [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
    $pipes,
    __DIR__,
    null
);
if ($previousSecret === false) {
    putenv('WQ_QB_BRIDGE_SECRET');
} else {
    putenv('WQ_QB_BRIDGE_SECRET=' . $previousSecret);
}
if ($previousLocalAppData === false) {
    putenv('LOCALAPPDATA');
} else {
    putenv('LOCALAPPDATA=' . $previousLocalAppData);
}
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the compiled bridge.');
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $probe = @stream_socket_client('tcp://127.0.0.1:8765', $errno, $errorMessage, 0.1);
        if ($probe !== false) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    if (!$ready) {
        throw new RuntimeException('The compiled bridge did not bind to 127.0.0.1:8765.');
    }

    $client = new QuickBooksBridgeClient('http://127.0.0.1:8765', $secret);
    $health = $client->health();
    server_test_assert($health['available'] && $health['response']['sdkRegistered'] === true, 'PHP client validates a live C# bridge health response and HMAC');

    $healthBody = json_encode(['operation' => 'health', 'requestId' => '11111111-1111-4111-8111-111111111111'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $requestId = '11111111-1111-4111-8111-111111111111';
    $valid = server_test_request('http://127.0.0.1:8765/health', $healthBody, $requestId, 'health', $secret);
    server_test_assert($valid['status'] === 200 && server_test_verify_response($valid, $requestId, $secret), 'bridge success response is authenticated over exact response bytes');
    $replay = server_test_request('http://127.0.0.1:8765/health', $healthBody, $requestId, 'health', $secret);
    server_test_assert($replay['status'] === 409 && server_test_verify_response($replay, $requestId, $secret), 'bridge rejects a replayed nonce and signs the rejection');

    $requestId = '22222222-2222-4222-8222-222222222222';
    $body = json_encode(['operation' => 'health', 'requestId' => $requestId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $invalidSignature = server_test_request(
        'http://127.0.0.1:8765/health',
        $body,
        $requestId,
        'health',
        $secret,
        null,
        str_repeat('0', 64)
    );
    server_test_assert($invalidSignature['status'] === 401 && server_test_verify_response($invalidSignature, '22222222-2222-4222-8222-222222222222', $secret), 'bridge rejects invalid request HMAC and authenticates the error response');

    $requestId = '33333333-3333-4333-8333-333333333333';
    $body = json_encode(['operation' => 'health', 'requestId' => $requestId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stale = server_test_request(
        'http://127.0.0.1:8765/health',
        $body,
        $requestId,
        'health',
        $secret,
        (string)(time() - 120)
    );
    server_test_assert($stale['status'] === 401, 'bridge rejects stale signed timestamps');

    $extraFieldBody = json_encode([
        'operation' => 'health',
        'requestId' => '44444444-4444-4444-8444-444444444444',
        'unexpected' => 'not-allowed',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $extraField = server_test_request(
        'http://127.0.0.1:8765/health',
        $extraFieldBody,
        '44444444-4444-4444-8444-444444444444',
        'health',
        $secret
    );
    server_test_assert($extraField['status'] === 400, 'bridge rejects unexpected JSON fields');

    $unknownBody = json_encode([
        'operation' => 'InvoiceAdd',
        'requestId' => '55555555-5555-4555-8555-555555555555',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $unknown = server_test_request(
        'http://127.0.0.1:8765/InvoiceAdd',
        $unknownBody,
        '55555555-5555-4555-8555-555555555555',
        'InvoiceAdd',
        $secret
    );
    server_test_assert($unknown['status'] === 400, 'transaction-creation operation is rejected before SDK access');

    $requestId = '66666666-6666-4666-8666-666666666666';
    $body = json_encode(['operation' => 'health', 'requestId' => $requestId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $wrongContentType = server_test_request(
        'http://127.0.0.1:8765/health',
        $body,
        $requestId,
        'health',
        $secret,
        null,
        null,
        'text/plain'
    );
    server_test_assert($wrongContentType['status'] === 400, 'bridge rejects unexpected content types');

    $oversizedBody = str_repeat('x', 524289);
    $oversized = server_test_request(
        'http://127.0.0.1:8765/health',
        $oversizedBody,
        '77777777-7777-4777-8777-777777777777',
        'health',
        $secret
    );
    server_test_assert($oversized['status'] === 413, 'bridge rejects oversized requests before parsing');

    echo "All compiled C# bridge HTTP checks passed.\n";
} finally {
    proc_terminate($process);
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(50000);
    }
    proc_close($process);
    $replayFile = $tempDirectory . DIRECTORY_SEPARATOR . 'MFC-Operations' . DIRECTORY_SEPARATOR . 'QuickBooksBridge' . DIRECTORY_SEPARATOR . 'replay-cache.txt';
    if (is_file($replayFile)) {
        unlink($replayFile);
    }
    $bridgeDataDirectory = dirname($replayFile);
    if (is_dir($bridgeDataDirectory)) {
        rmdir($bridgeDataDirectory);
    }
    $applicationDataDirectory = dirname($bridgeDataDirectory);
    if (is_dir($applicationDataDirectory)) {
        rmdir($applicationDataDirectory);
    }
    if (is_file($executable)) {
        unlink($executable);
    }
    if (is_dir($tempDirectory)) {
        rmdir($tempDirectory);
    }
}
