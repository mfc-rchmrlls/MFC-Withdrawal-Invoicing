<?php

declare(strict_types=1);
require_once __DIR__ . '/../backend/core/quickbooks_exports.php';
require_once __DIR__ . '/../backend/core/quickbooks_bridge_client.php';

function posting_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

function posting_has_error(array $validation, string $code): bool
{
    foreach ($validation['errors'] ?? [] as $error) {
        if (($error['code'] ?? null) === $code) {
            return true;
        }
    }
    return false;
}

function posting_fixture(bool $addMappings = true, bool $expiration = false, bool $unit = false): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY,client_name TEXT,qb_list_id TEXT,active INTEGER)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY,product_name TEXT,qb_list_id TEXT,qb_code TEXT,item_code TEXT,qb_description TEXT,description TEXT,u_m TEXT,active INTEGER)');
    $pdo->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY,site_name TEXT,active INTEGER)');
    $pdo->exec('CREATE TABLE withdrawals (id INTEGER PRIMARY KEY,withdrawal_no TEXT)');
    $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY,withdrawal_id INTEGER,customer_id INTEGER,invoice_number TEXT,invoice_date TEXT,po_no TEXT,memo TEXT,status TEXT)');
    $pdo->exec('CREATE TABLE invoice_items (id INTEGER PRIMARY KEY,invoice_id INTEGER,withdrawal_item_id INTEGER,source_type TEXT,product_id INTEGER,site_id INTEGER,product_name TEXT,quantity INTEGER,lot_number TEXT,item_code TEXT,description TEXT,u_m TEXT,price_each TEXT,amount TEXT,tax_code TEXT,expiration_date TEXT)');
    $pdo->exec('CREATE TABLE qb_jobs (id INTEGER PRIMARY KEY,invoice_id INTEGER)');
    $pdo->exec('CREATE TABLE employees (id INTEGER PRIMARY KEY,role TEXT,active INTEGER)');
    $pdo->exec('CREATE TABLE qb_export_reference_verifications (id INTEGER PRIMARY KEY AUTOINCREMENT,reference_type TEXT,local_key TEXT,qb_list_id TEXT,qb_full_name TEXT,verified_at TEXT,verified_by INTEGER,company_file_fingerprint TEXT,diagnostic_request_id TEXT,evidence_hmac TEXT,active INTEGER,UNIQUE(reference_type,local_key),UNIQUE(reference_type,qb_list_id))');
    $pdo->exec('CREATE TABLE qb_exports (id INTEGER PRIMARY KEY AUTOINCREMENT,export_id TEXT UNIQUE,transaction_type TEXT,source_transaction_id INTEGER,source_revision TEXT,snapshot_json TEXT,status TEXT DEFAULT "pending",attempt_count INTEGER DEFAULT 0,qb_txn_id TEXT,qb_ref_number TEXT,claim_token TEXT,lease_until TEXT,created_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT,completed_at TEXT,UNIQUE(transaction_type,source_transaction_id))');
    $pdo->exec('CREATE TABLE qb_export_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT,export_id TEXT,attempt_number INTEGER,request_id TEXT UNIQUE,outcome TEXT,started_at TEXT,completed_at TEXT,diagnostic_code TEXT,diagnostic_message TEXT,UNIQUE(export_id,attempt_number))');
    $pdo->exec('CREATE TABLE qb_export_events (id INTEGER PRIMARY KEY AUTOINCREMENT,export_id TEXT,event_type TEXT,from_status TEXT,to_status TEXT,actor_id INTEGER,request_id TEXT,diagnostic_code TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE activity_logs (employee_id INTEGER,action TEXT,entity_type TEXT,entity_id INTEGER,details TEXT,ip_address TEXT,created_at TEXT)');
    $pdo->exec("INSERT INTO clients VALUES(1,'Mock Customer','CUST-MOCK',1)");
    $pdo->exec("INSERT INTO products VALUES(1,'Mock Product','ITEM-MOCK','CODE-1','LOCAL-CODE','Mock Item','Mock Item','',1)");
    $pdo->exec("INSERT INTO sites VALUES(1,'Main Site',1)");
    $pdo->exec("INSERT INTO withdrawals VALUES(1,'W-MOCK')");
    $pdo->exec("INSERT INTO invoices VALUES(1,1,1,'INV-MOCK','2026-10-23','PO-MOCK',NULL,'Draft')");
    $expirationDate = $expiration ? "'2026-12-31'" : 'NULL';
    $unit = $unit ? 'Each' : '';
    $pdo->exec("INSERT INTO invoice_items VALUES
        (1,1,NULL,'WITHDRAWAL',1,1,'Mock Product',2,'','LOCAL-CODE', 'Mock invoice line','{$unit}','10.00','20.00','Tax',{$expirationDate}),
        (2,1,NULL,'WITHDRAWAL',1,1,'Mock Product',1,'','LOCAL-CODE', 'Second mock line','{$unit}','5.00','5.00','Tax',NULL)");
    $pdo->exec("INSERT INTO employees VALUES(1,'Admin',1)");
    if ($addMappings) {
        foreach ([
            ['customer', 'client:1', 'CUST-MOCK', 'Mock Customer'],
            ['item', 'product:1', 'ITEM-MOCK', 'Mock Item'],
            ['tax_code', 'tax:Tax', 'TAX-MOCK', 'Tax'],
        ] as [$type, $key, $listId, $name]) {
            $evidence = [
                'reference_type' => $type,
                'local_key' => $key,
                'qb_list_id' => $listId,
                'qb_full_name' => $name,
                'active' => 1,
                'company_file_fingerprint' => str_repeat('b', 64),
                'diagnostic_request_id' => qb_export_uuid(),
                'verified_at' => '2026-10-23 12:00:00',
                'verified_by' => 1,
            ];
            $evidence['evidence_hmac'] = qb_export_verification_signature($evidence, str_repeat('v', 40));
            $insert = $pdo->prepare(
                'INSERT INTO qb_export_reference_verifications
                 (reference_type,local_key,qb_list_id,qb_full_name,verified_at,verified_by,
                  company_file_fingerprint,diagnostic_request_id,evidence_hmac,active)
                 VALUES(?,?,?,?,?,?,?,?,?,1)'
            );
            $insert->execute([
                $type, $key, $listId, $name, $evidence['verified_at'], 1,
                $evidence['company_file_fingerprint'], $evidence['diagnostic_request_id'], $evidence['evidence_hmac'],
            ]);
        }
    }
    return $pdo;
}

function posting_signed_response(int $status, array $response, string $secret, bool $addCompanyIdentity = false): array
{
    if ($addCompanyIdentity && ($response['companyIdentityVerified'] ?? false) === true) {
        $response['companyIdentity'] = [
            'companyName' => 'Mock QuickBooks Company',
            'legalCompanyName' => 'Mock QuickBooks Company LLC',
        ];
    }
    $body = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $requestId = $response['requestId'];
    $timestamp = (string)time();
    return [
        'status' => $status,
        'body' => $body,
        'headers' => [
            'x-mfc-timestamp' => $timestamp,
            'x-mfc-signature' => hash_hmac(
                'sha256',
                $timestamp . "\nresponse\n" . $requestId . "\n" . $status . "\n" . hash('sha256', $body),
                $secret
            ),
        ],
    ];
}

function posting_bridge(string $mode, array &$counters, string $secret, string $fingerprint = ''): QuickBooksBridgeClient
{
    return new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body, array $headers) use ($mode, &$counters, $secret, $fingerprint): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $operation = basename((string)parse_url($url, PHP_URL_PATH));
            if ($operation === 'submit') {
                $counters['writes']++;
                $counters['write_request'] = $request;
                if ($mode === 'timeout') {
                    throw new RuntimeException('Mock timeout after dispatch.');
                }
                if ($mode === 'reject') {
                    return posting_signed_response(422, [
                        'requestId' => $request['requestId'],
                        'status' => 'error',
                        'diagnosticCode' => 'QUICKBOOKS_REJECTED',
                        'outcome' => 'rejected',
                        'message' => 'QuickBooks rejected the invoice (status 3140). Invalid item reference.',
                    ], $secret);
                }
                return posting_signed_response(200, [
                    'requestId' => $request['requestId'],
                    'status' => 'ok',
                    'outcome' => 'succeeded',
                    'txnId' => 'TXN-MOCK-123',
                    'txnNumber' => '123',
                    'companyFingerprint' => $fingerprint ?: str_repeat('b', 64),
                ], $secret);
            }

            $counters['diagnostics']++;
            if ($operation === 'health') {
                return posting_signed_response(200, [
                    'requestId' => $request['requestId'],
                    'status' => 'ok',
                    'sdkRegistered' => true,
                    'clrVersion' => '4.0',
                    'dotNetFrameworkRelease' => '533320',
                    'quickBooksProcessRunning' => false,
                    'companySessionChecked' => false,
                    'companySessionReady' => null,
                    'invoiceWriteEnabled' => $mode !== 'bridge-disabled',
                ], $secret);
            }

            $entity = $request['entity'];
            $mockFingerprint = $fingerprint ?: str_repeat('b', 64);
            $records = [];
            if ($entity === 'company_preferences') {
                $records[] = [
                    'customerTaxCodeEnabled' => true,
                    'vendorTaxCodeEnabled' => null,
                    'amountsIncludeTax' => false,
                    'defaultTaxableCode' => ['listId' => 'TAX-MOCK', 'fullName' => 'Tax'],
                    'defaultNonTaxableCode' => ['listId' => 'TAX-MOCK', 'fullName' => 'Tax'],
                    'defaultTaxItem' => ['listId' => null, 'fullName' => null],
                    'multiLocationAvailable' => true,
                    'multiLocationEnabled' => true,
                    'lotTracking' => null,
                    'expirationDateEnabled' => false,
                    'salesTransactionTrackingEnabled' => null,
                ];
            } elseif (isset($request['searchName'])) {
                $records[] = [
                    'listId' => 'SITE-MOCK',
                    'name' => 'Main Site',
                    'fullName' => $mode === 'bad-site' ? 'Other Site' : $request['searchName'],
                    'active' => $mode !== 'bad-site',
                ];
            } else {
                foreach ($request['listIds'] as $listId) {
                    if ($entity === 'item_uom') {
                        $records[] = [
                            'listId' => $listId,
                            'found' => true,
                            'itemName' => 'Mock Item',
                            'active' => true,
                            'unitSetListId' => 'UNIT-SET',
                            'unitSetName' => 'Each',
                            'unitSetActive' => true,
                            'unitType' => 'Count',
                            'units' => [['name' => 'Each', 'abbreviation' => 'ea']],
                            'salesDefaultUnit' => 'Each',
                        ];
                        continue;
                    }
                    $active = !($mode === 'bad-reference' && $listId === 'ITEM-MOCK')
                        && !($mode === 'bad-tax' && $entity === 'tax_code');
                    $record = [
                        'listId' => $listId,
                        'name' => 'Mock Reference',
                        'active' => $active,
                        'found' => true,
                    ];
                    if ($entity === 'item') {
                        $record['manufacturerPartNumber'] = null;
                    }
                    $records[] = $record;
                }
            }

            return posting_signed_response(200, [
                'requestId' => $request['requestId'],
                'status' => 'ok',
                'entity' => $entity,
                'records' => $records,
                'companyFingerprint' => $mockFingerprint,
                'companyIdentityVerified' => true,
                'qbxmlVersion' => '16.0',
                ... (isset($request['searchName']) ? ['searchName' => $request['searchName']] : []),
            ], $secret, true);
        }
    );
}

function posting_create_export(PDO $pdo): string
{
    $preview = qb_export_validate($pdo, 'invoice', 1);
    if (!$preview['valid']) {
        throw new RuntimeException('Mock invoice fixture is invalid: ' . json_encode($preview['errors']));
    }
    return qb_export_create($pdo, 'invoice', 1, $preview['source_revision'], 1)['export_id'];
}

putenv('WQ_QB_VERIFICATION_SECRET=' . str_repeat('v', 40));
putenv('WQ_QB_COMPANY_FINGERPRINT=' . str_repeat('b', 64));
putenv('WQ_QB_INVOICE_WRITE_ENABLED=true');
$secret = str_repeat('s', 40);

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$bridge = posting_bridge('success', $counters, $secret);
$preflight = qb_export_post_preflight($pdo, $exportId, $bridge);
posting_assert($preflight['ready'] === true, 'mock preflight verifies company settings and customer, item, tax, and site references: ' . json_encode($preflight['errors'] ?? []));
$result = qb_export_post($pdo, $exportId, 1, $bridge);
posting_assert($result['outcome'] === 'succeeded' && $result['txn_id'] === 'TXN-MOCK-123', 'two-line immutable invoice snapshot receives a confirmed mock InvoiceAdd: ' . json_encode($result));
posting_assert(!isset($counters['write_request']['transaction']['lines'][0]['itemCode']),
    'local Item Code is not sent as an unverified QuickBooks field');
$saved = $pdo->query('SELECT status,qb_txn_id,qb_ref_number FROM qb_exports')->fetch();
posting_assert($saved['status'] === 'succeeded' && $saved['qb_txn_id'] === 'TXN-MOCK-123' && $saved['qb_ref_number'] === 'INV-MOCK', 'confirmed QuickBooks transaction ID and local reference are persisted');
posting_assert((int)$pdo->query("SELECT COUNT(*) FROM qb_export_attempts WHERE outcome='succeeded'")->fetchColumn() === 1, 'successful attempt is durably recorded and finalized');
$duplicate = qb_export_post($pdo, $exportId, 1, $bridge);
posting_assert($duplicate['outcome'] === 'blocked' && $counters['writes'] === 1, 'duplicate submission cannot produce a second mock write');

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$result = qb_export_post($pdo, $exportId, 1, posting_bridge('reject', $counters, $secret));
posting_assert($result['outcome'] === 'rejected', 'signed QuickBooks rejection is persisted as failed');
$savedMessage = (string)$pdo->query('SELECT diagnostic_message FROM qb_export_attempts')->fetchColumn();
posting_assert(str_contains($savedMessage, 'Invalid item reference') && !str_contains($savedMessage, '<QBXML'), 'rejection error is sanitized before persistence');

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$timeoutBridge = posting_bridge('timeout', $counters, $secret);
$result = qb_export_post($pdo, $exportId, 1, $timeoutBridge);
posting_assert($result['outcome'] === 'unknown'
    && $pdo->query('SELECT status FROM qb_exports')->fetchColumn() === 'unknown',
    'ambiguous timeout persists unknown instead of claiming success or failure');
$retry = qb_export_post($pdo, $exportId, 1, $timeoutBridge);
posting_assert($retry['outcome'] === 'blocked' && $counters['writes'] === 1, 'unknown outcome cannot be automatically retried');

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$claimOne = qb_export_uuid();
$claimTwo = qb_export_uuid();
posting_assert(qb_export_claim($pdo, $exportId, qb_export_validate($pdo, 'invoice', 1, $exportId)['source_revision'], $claimOne), 'first competing export submission atomically claims pending state');
posting_assert(!qb_export_claim($pdo, $exportId, qb_export_validate($pdo, 'invoice', 1, $exportId)['source_revision'], $claimTwo), 'second competing export submission cannot claim the same export');

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$wrongCompanyBridge = posting_bridge('success', $counters, $secret, str_repeat('c', 64));
$preflight = qb_export_post_preflight($pdo, $exportId, $wrongCompanyBridge);
posting_assert($preflight['ready'] === false && $counters['writes'] === 0, 'company-fingerprint mismatch prevents a write');

$pdo = posting_fixture(false);
$validation = qb_export_validate($pdo, 'invoice', 1);
posting_assert(!$validation['valid']
    && posting_has_error($validation, 'CUSTOMER_MAPPING_UNVERIFIED')
    && posting_has_error($validation, 'ITEM_MAPPING_UNVERIFIED'),
    'missing approved customer and item references prevent export readiness');

$pdo = posting_fixture(true, true);
posting_assert(posting_has_error(qb_export_validate($pdo, 'invoice', 1), 'EXPIRATION_DESTINATION_UNVERIFIED'),
    'unsupported expiration date mapping blocks posting before submission');

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$badMappings = posting_bridge('bad-site', $counters, $secret);
$preflight = qb_export_post_preflight($pdo, $exportId, $badMappings);
posting_assert($preflight['ready'] === false && $counters['writes'] === 0, 'unverified site mapping prevents a write');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$badTax = posting_bridge('bad-tax', $counters, $secret);
$preflight = qb_export_post_preflight($pdo, $exportId, $badTax);
posting_assert($preflight['ready'] === false && $counters['writes'] === 0, 'unverified tax mapping prevents a write');

$pdo = posting_fixture(true, false, true);
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$unitBridge = posting_bridge('success', $counters, $secret);
$preflight = qb_export_post_preflight($pdo, $exportId, $unitBridge);
posting_assert($preflight['ready'] === true, 'verified item sales unit is accepted after live unit-set preflight');

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
putenv('WQ_QB_INVOICE_WRITE_ENABLED=0');
$counters = ['writes' => 0, 'diagnostics' => 0];
$disabled = qb_export_post($pdo, $exportId, 1, posting_bridge('success', $counters, $secret));
putenv('WQ_QB_INVOICE_WRITE_ENABLED=true');
posting_assert($disabled['outcome'] === 'blocked' && $counters['writes'] === 0, 'disabled PHP write configuration prevents all real transport writes');

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$bridgeDisabled = qb_export_post($pdo, $exportId, 1, posting_bridge('bridge-disabled', $counters, $secret));
posting_assert($bridgeDisabled['outcome'] === 'blocked' && $counters['writes'] === 0, 'disabled bridge configuration prevents any QuickBooks write');

echo "All mocked invoice posting lifecycle tests passed.\n";
