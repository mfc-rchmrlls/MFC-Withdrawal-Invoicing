<?php

declare(strict_types=1);

require_once __DIR__ . '/quickbooks_exports.php';

final class QuickBooksBridgeClient
{
    private string $baseUrl;
    private ?string $secret;
    private $transport;

    public function __construct(?string $baseUrl = null, ?string $secret = null, ?callable $transport = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (getenv('WQ_QB_BRIDGE_URL') ?: 'http://127.0.0.1:8765'), '/');
        $this->secret = $secret ?? (getenv('WQ_QB_BRIDGE_SECRET') ?: null);
        $this->transport = $transport;
    }

    public function health(): array
    {
        return $this->request('health', ['operation' => 'health']);
    }

    public function diagnose(string $entity, array $listIds): array
    {
        if (!in_array($entity, ['customer', 'item', 'tax_code', 'tax_item', 'inventory_site'], true)
            || count($listIds) < 1
            || count($listIds) > 50) {
            throw new InvalidArgumentException('The diagnostic request has an unsupported entity or page size.');
        }
        foreach ($listIds as $listId) {
            if (!is_string($listId)
                || trim($listId) === ''
                || strlen($listId) > 100
                || !preg_match('/^[A-Za-z0-9._:-]+$/D', $listId)) {
                throw new InvalidArgumentException('The diagnostic request contains an invalid QuickBooks ListID.');
            }
        }
        if (count(array_unique($listIds, SORT_STRING)) !== count($listIds)) {
            throw new InvalidArgumentException('The diagnostic request contains duplicate QuickBooks ListIDs.');
        }

        return $this->request('diagnose', [
            'operation' => 'diagnose',
            'entity' => $entity,
            'listIds' => array_values($listIds),
        ], 15);
    }

    public function validate(array $request): array
    {
        $type = $request['transactionType'] ?? null;
        if (!in_array($type, ['invoice', 'sales_order'], true)) {
            throw new InvalidArgumentException('Unsupported QuickBooks transaction type.');
        }
        if (($request['operation'] ?? null) !== 'validate' || ($request['validationOnly'] ?? null) !== true) {
            throw new InvalidArgumentException('Only validation-only bridge requests are supported.');
        }
        $allowedKeys = [
            'protocolVersion', 'requestId', 'operation', 'validationOnly', 'idempotencyKey',
            'transactionType', 'source', 'transaction',
        ];
        if (array_diff(array_keys($request), $allowedKeys)
            || array_diff(['protocolVersion', 'requestId', 'operation', 'validationOnly', 'idempotencyKey', 'transactionType', 'source', 'transaction'], array_keys($request))
            || ($request['protocolVersion'] ?? null) !== '1'
            || !is_string($request['requestId'] ?? null)
            || !preg_match('/^[a-f0-9-]{36}$/iD', $request['requestId'])
            || ($request['idempotencyKey'] ?? null) !== null
            || !is_array($request['source'] ?? null)
            || !is_array($request['transaction'] ?? null)) {
            throw new InvalidArgumentException('The validation-only request has invalid or unexpected fields.');
        }
        $transactionKeys = [
            'customer', 'txnDate', 'refNumber', 'poNumber', 'memo', 'terms',
            'class', 'billAddress', 'shipAddress', 'lines', 'expectedSubtotal',
            'expectedTax', 'expectedTotal',
        ];
        $lineKeys = [
            'item', 'description', 'quantity', 'rate', 'expectedAmount',
            'salesTaxCode', 'site', 'lotNumber', 'expirationDate', 'unitOfMeasure',
        ];
        $requiredTransactionKeys = [
            'customer', 'txnDate', 'refNumber', 'poNumber', 'memo', 'lines',
            'expectedSubtotal', 'expectedTax', 'expectedTotal',
        ];
        if (array_diff(array_keys($request['transaction']), $transactionKeys)
            || array_diff($requiredTransactionKeys, array_keys($request['transaction']))
            || !is_array($request['transaction']['customer'] ?? null)
            || !is_array($request['transaction']['lines'] ?? null)
            || array_diff(array_keys($request['transaction']['customer']), ['listId'])) {
            throw new InvalidArgumentException('The validation-only transaction has invalid or unexpected fields.');
        }
        if (!is_string($request['transaction']['customer']['listId'] ?? null)
            || trim($request['transaction']['customer']['listId']) === '') {
            throw new InvalidArgumentException('The validation-only customer reference is invalid.');
        }
        if (array_diff(array_keys($request['source']), ['id', 'revision'])
            || !isset($request['source']['id'], $request['source']['revision'])
            || !filter_var($request['source']['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            || !is_string($request['source']['revision'])
            || !preg_match('/^[a-f0-9]{64}$/D', $request['source']['revision'])) {
            throw new InvalidArgumentException('The validation-only source reference is invalid.');
        }
        $transaction = $request['transaction'];
        if (!is_string($transaction['txnDate'] ?? null)
            || !qb_export_valid_date($transaction['txnDate'])
            || !is_string($transaction['refNumber'] ?? null)
            || trim($transaction['refNumber']) === ''
            || mb_strlen($transaction['refNumber']) > 100
            || !$this->isNullableString($transaction['poNumber'] ?? null, 100)
            || !$this->isNullableString($transaction['memo'] ?? null, 65535)
            || !$this->isMoneyOrNull($transaction['expectedSubtotal'] ?? null, false)
            || !$this->isMoneyOrNull($transaction['expectedTax'] ?? null, true)
            || !$this->isMoneyOrNull($transaction['expectedTotal'] ?? null, true)
            || $transaction['expectedTax'] !== null
            || $transaction['expectedTotal'] !== null
            || count($transaction['lines']) < 1
            || count($transaction['lines']) > 500) {
            throw new InvalidArgumentException('The validation-only transaction values are invalid.');
        }
        foreach (['terms', 'class'] as $optionalString) {
            if (array_key_exists($optionalString, $transaction) && !$this->isNullableString($transaction[$optionalString], 100)) {
                throw new InvalidArgumentException('The validation-only transaction values are invalid.');
            }
        }
        foreach (['billAddress', 'shipAddress'] as $addressField) {
            if (array_key_exists($addressField, $transaction) && !$this->isAddress($transaction[$addressField])) {
                throw new InvalidArgumentException('The validation-only transaction address is invalid.');
            }
        }
        $subtotalCents = 0;
        foreach ($transaction['lines'] as $line) {
            if (!is_array($line)
                || array_diff(array_keys($line), $lineKeys)
                || array_diff(['item', 'description', 'quantity', 'rate', 'expectedAmount', 'salesTaxCode'], array_keys($line))
                || !is_array($line['item'] ?? null)
                || array_diff(array_keys($line['item']), ['listId'])
                || !is_string($line['item']['listId'] ?? null)
                || trim($line['item']['listId']) === ''
                || !is_array($line['salesTaxCode'] ?? null)
                || array_diff(array_keys($line['salesTaxCode']), ['listId'])
                || !is_string($line['salesTaxCode']['listId'] ?? null)
                || trim($line['salesTaxCode']['listId']) === ''
                || !is_string($line['description'] ?? null)
                || trim($line['description']) === ''
                || mb_strlen($line['description']) > 255
                || !is_string($line['quantity'] ?? null)
                || !preg_match('/^[1-9]\d{0,8}$/D', $line['quantity'])
                || !$this->isMoneyOrNull($line['rate'] ?? null, false)
                || !$this->isMoneyOrNull($line['expectedAmount'] ?? null, false)) {
                throw new InvalidArgumentException('The validation-only line has invalid or unexpected fields.');
            }
            $quantity = (int)$line['quantity'];
            $rateCents = qb_export_money_cents($line['rate']);
            $amountCents = qb_export_money_cents($line['expectedAmount']);
            $maximumCents = 99999999999999;
            if ($rateCents === null
                || $amountCents === null
                || ($rateCents > 0 && $quantity > intdiv($maximumCents, $rateCents))
                || $quantity * $rateCents !== $amountCents
                || $subtotalCents > $maximumCents - $amountCents) {
                throw new InvalidArgumentException('The validation-only line amount does not reconcile to quantity and rate.');
            }
            $subtotalCents += $amountCents;
            foreach (['site', 'lotNumber', 'expirationDate', 'unitOfMeasure'] as $optionalLineValue) {
                if (array_key_exists($optionalLineValue, $line)
                    && !$this->isNullableString($line[$optionalLineValue], 255)) {
                    throw new InvalidArgumentException('The validation-only line values are invalid.');
                }
            }
            if (array_key_exists('expirationDate', $line)
                && $line['expirationDate'] !== null
                && !qb_export_valid_date($line['expirationDate'])) {
                throw new InvalidArgumentException('The validation-only expiration date is invalid.');
            }
        }
        if ($subtotalCents !== qb_export_money_cents($transaction['expectedSubtotal'])) {
            throw new InvalidArgumentException('The validation-only subtotal does not reconcile to its line amounts.');
        }
        return $this->request('validate', $request);
    }

    private function isNullableString(mixed $value, int $maxLength): bool
    {
        return $value === null || (is_string($value) && mb_strlen($value) <= $maxLength);
    }

    private function isMoneyOrNull(mixed $value, bool $nullable): bool
    {
        return ($nullable && $value === null)
            || (is_string($value) && qb_export_money_cents($value) !== null);
    }

    private function isAddress(mixed $address): bool
    {
        if ($address === null) {
            return true;
        }
        if (!is_array($address)) {
            return false;
        }
        $allowed = ['addr1', 'addr2', 'addr3', 'addr4', 'addr5', 'city', 'state', 'postalCode', 'country'];
        if (array_diff(array_keys($address), $allowed)) {
            return false;
        }
        foreach ($address as $value) {
            if (!$this->isNullableString($value, 41)) {
                return false;
            }
        }
        return true;
    }

    private function request(string $operation, array $payload, int $timeout = 5): array
    {
        if (!in_array($operation, ['health', 'validate', 'diagnose'], true)) {
            throw new InvalidArgumentException('Unsupported bridge operation.');
        }
        if (!$this->isLoopbackUrl($this->baseUrl)
            || !is_string($this->secret)
            || strlen($this->secret) < 32) {
            return [
                'available' => false,
                'request_id' => $payload['requestId'] ?? qb_export_uuid(),
                'diagnostic_code' => !$this->isLoopbackUrl($this->baseUrl) ? 'BRIDGE_DESTINATION_REJECTED' : 'BRIDGE_NOT_CONFIGURED',
                'message' => !$this->isLoopbackUrl($this->baseUrl)
                    ? 'The bridge destination must be literal loopback HTTP.'
                    : 'The authenticated loopback bridge or its minimum-length secret is not configured.',
            ];
        }
        if ($this->transport === null && !function_exists('curl_init')) {
            return [
                'available' => false,
                'request_id' => $payload['requestId'] ?? qb_export_uuid(),
                'diagnostic_code' => 'HTTP_CLIENT_UNAVAILABLE',
                'message' => 'The PHP cURL extension is not available.',
            ];
        }

        $requestId = qb_export_uuid();
        $payload['requestId'] = $requestId;
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . "\n" . $operation . "\n" . $requestId . "\n" . hash('sha256', $body), $this->secret);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-MFC-Request-Id: ' . $requestId,
            'X-MFC-Timestamp: ' . $timestamp,
            'X-MFC-Signature: ' . $signature,
        ];
        if ($this->transport !== null) {
            try {
                $transportResult = ($this->transport)($this->baseUrl . '/' . $operation, $body, $headers);
                if (!is_array($transportResult)) {
                    throw new RuntimeException('Invalid HTTP transport result.');
                }
                $transportHeaders = $this->normalizeHeaders($transportResult['headers'] ?? []);
                return $this->decodeResponse(
                    (int)($transportResult['status'] ?? 0),
                    (string)($transportResult['body'] ?? ''),
                    $requestId,
                    $operation,
                    $transportHeaders,
                    $payload['entity'] ?? null,
                    $payload['listIds'] ?? null
                );
            } catch (Throwable) {
                return [
                    'available' => false,
                    'request_id' => $requestId,
                    'diagnostic_code' => 'BRIDGE_UNAVAILABLE',
                    'message' => 'The local bridge did not return a complete response.',
                ];
            }
        }

        $handle = curl_init($this->baseUrl . '/' . $operation);
        $responseHeaders = [];
        $duplicateResponseHeaders = false;
        $responseTooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders, &$duplicateResponseHeaders): int {
                if (preg_match('/^([^:]+):\s*(.*?)\s*$/', $line, $matches)) {
                    $name = strtolower($matches[1]);
                    if (in_array($name, ['x-mfc-timestamp', 'x-mfc-signature'], true)) {
                        if (array_key_exists($name, $responseHeaders)) {
                            $duplicateResponseHeaders = true;
                        }
                        $responseHeaders[$name] = $matches[2];
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response, &$responseTooLarge): int {
                $response ??= '';
                if (strlen($response) + strlen($chunk) > 65536) {
                    $responseTooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        $response = '';
        $result = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);

        if ($responseTooLarge) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_RESPONSE_TOO_LARGE',
                'message' => 'The local bridge response exceeded the configured limit.',
            ];
        }
        if ($result === false || $curlError !== 0) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_UNAVAILABLE',
                'message' => 'The local bridge did not return a complete response.',
            ];
        }
        if ($duplicateResponseHeaders) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_INVALID_RESPONSE_HEADERS',
                'message' => 'The local bridge returned duplicate authentication headers.',
            ];
        }
        return $this->decodeResponse(
            $status,
            $response,
            $requestId,
            $operation,
            $responseHeaders,
            $payload['entity'] ?? null,
            $payload['listIds'] ?? null
        );
    }

    private function decodeResponse(
        int $status,
        string $response,
        string $requestId,
        string $operation,
        array $headers,
        ?string $expectedEntity = null,
        ?array $expectedListIds = null
    ): array
    {
        if (strlen($response) > 65536) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_RESPONSE_TOO_LARGE',
                'message' => 'The local bridge response exceeded the configured limit.',
            ];
        }
        $timestamp = $headers['x-mfc-timestamp'] ?? '';
        $signature = $headers['x-mfc-signature'] ?? '';
        if (!is_string($timestamp)
            || !preg_match('/^\d{10}$/D', $timestamp)
            || abs(time() - (int)$timestamp) > 30
            || !is_string($signature)
            || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_RESPONSE_AUTH_FAILED',
                'message' => 'The local bridge response is unsigned or stale.',
            ];
        }
        $expectedSignature = hash_hmac(
            'sha256',
            $timestamp . "\nresponse\n" . $requestId . "\n" . $status . "\n" . hash('sha256', $response),
            (string)$this->secret
        );
        if (!hash_equals($expectedSignature, $signature)) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_RESPONSE_AUTH_FAILED',
                'message' => 'The local bridge response signature is invalid.',
            ];
        }

        try {
            $decoded = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_INVALID_RESPONSE',
                'message' => 'The local bridge returned invalid JSON.',
            ];
        }
        $allowedResponseKeys = [
            'requestId', 'status', 'valid', 'errors', 'warnings', 'diagnosticCode',
            'sdkRegistered', 'clrVersion', 'dotNetFrameworkRelease', 'quickBooksProcessRunning', 'companySessionChecked',
            'companySessionReady', 'companyFingerprint', 'validationOnly',
            'entity', 'records', 'qbxmlVersion',
        ];
        $invalidHealth = $operation === 'health'
            && ($decoded['status'] ?? null) === 'ok'
            && ((!is_bool($decoded['sdkRegistered'] ?? null))
                || !is_string($decoded['clrVersion'] ?? null)
                || !array_key_exists('dotNetFrameworkRelease', $decoded)
                || (!is_null($decoded['dotNetFrameworkRelease']) && !is_string($decoded['dotNetFrameworkRelease']))
                || (!is_bool($decoded['quickBooksProcessRunning'] ?? null))
                || (!is_bool($decoded['companySessionChecked'] ?? null))
                || (!is_null($decoded['companySessionReady'] ?? null)
                    && !is_bool($decoded['companySessionReady'])));
        $invalidDiagnosis = $operation === 'diagnose';
        if ($operation === 'diagnose' && is_array($decoded) && is_string($decoded['status'] ?? null)) {
            $invalidDiagnosis = false;
            if ($decoded['status'] === 'ok') {
                $records = $decoded['records'] ?? null;
                $invalidDiagnosis = ($decoded['entity'] ?? null) !== $expectedEntity
                    || !is_array($records)
                    || !array_is_list($records)
                    || !is_array($expectedListIds)
                    || count($records) !== count($expectedListIds)
                    || !is_string($decoded['companyFingerprint'] ?? null)
                    || !preg_match('/^[a-f0-9]{64}$/D', $decoded['companyFingerprint'])
                    || !is_string($decoded['qbxmlVersion'] ?? null);
                if (!$invalidDiagnosis) {
                    foreach ($records as $index => $record) {
                        if (!is_array($record)
                            || array_is_list($record)
                            || array_diff(array_keys($record), ['listId', 'name', 'active', 'found'])
                            || ($record['listId'] ?? null) !== $expectedListIds[$index]
                            || !is_bool($record['found'] ?? null)
                            || ($record['found']
                                && (!is_string($record['name'] ?? null)
                                    || !is_bool($record['active'] ?? null)))) {
                            $invalidDiagnosis = true;
                            break;
                        }
                    }
                }
            }
        }
        if (!is_array($decoded)
            || array_is_list($decoded)
            || array_diff(array_keys($decoded), $allowedResponseKeys)
            || ($decoded['requestId'] ?? null) !== $requestId
            || ($operation === 'health' && !is_string($decoded['status'] ?? null))
            || $invalidHealth
            || ($operation === 'validate' && !is_bool($decoded['valid'] ?? null))
            || $invalidDiagnosis) {
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => 'BRIDGE_RESPONSE_MISMATCH',
                'message' => 'The local bridge response did not match the request ID.',
            ];
        }
        if ($status < 200 || $status >= 300) {
            $responseCode = is_string($decoded['diagnosticCode'] ?? null)
                && preg_match('/^[A-Z0-9_]{1,80}$/D', $decoded['diagnosticCode'])
                ? $decoded['diagnosticCode']
                : ($status === 401 || $status === 403 ? 'BRIDGE_AUTH_FAILED' : 'BRIDGE_HTTP_ERROR');
            return [
                'available' => false,
                'request_id' => $requestId,
                'diagnostic_code' => $responseCode,
                'message' => 'The local bridge returned a signed unavailable or error response.',
            ];
        }
        return ['available' => true, 'request_id' => $requestId, 'response' => $decoded];
    }

    private function normalizeHeaders(mixed $headers): array
    {
        if (!is_array($headers)) {
            return [];
        }
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $normalized[strtolower($name)] = $value;
            }
        }
        return $normalized;
    }

    private function isLoopbackUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'http') {
            return false;
        }
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        return in_array($host, ['127.0.0.1', '::1'], true)
            && (!isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/')
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }
}
