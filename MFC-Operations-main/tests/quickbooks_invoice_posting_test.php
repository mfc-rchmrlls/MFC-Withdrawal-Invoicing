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
    $pdo->exec('CREATE TABLE invoice_items (id INTEGER PRIMARY KEY,invoice_id INTEGER,withdrawal_item_id INTEGER,source_type TEXT,product_id INTEGER,site_id INTEGER,product_name TEXT,quantity INTEGER,lot_number TEXT,item_code TEXT,description TEXT,u_m TEXT,price_each TEXT,amount TEXT,price_source TEXT,tax_code TEXT,expiration_date TEXT)');
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
        (1,1,NULL,'WITHDRAWAL',1,1,'Mock Product',2,'','LOCAL-CODE', 'Mock invoice line','{$unit}','10.00','20.00','mfc_entered','Tax',{$expirationDate}),
        (2,1,NULL,'WITHDRAWAL',1,1,'Mock Product',1,'','LOCAL-CODE', 'Second mock line','{$unit}','5.00','5.00','mfc_entered','Tax',NULL)");
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
            if (
                $mode === 'diagnostic-failure'
                && in_array($entity, ['customer', 'item', 'inventory_site'], true)
            ) {
                return posting_signed_response(503, [
                    'requestId' => $request['requestId'],
                    'status' => 'unavailable',
                    'diagnosticCode' => 'COMPANY_SESSION_UNAVAILABLE',
                    'failureStage' => 'begin_session',
                    'sdkErrorCode' => 'SDK_COM_80040418',
                ], $secret);
            }
            if ($entity === 'company_preferences') {
                $records[] = [
                    'customerTaxCodeEnabled' => $mode === 'null-customer-tax-pref'
                        ? null
                        : ($mode === 'false-customer-tax-pref' ? false : true),
                    'vendorTaxCodeEnabled' => null,
                    'amountsIncludeTax' => false,
                    'defaultTaxableCode' => ['listId' => 'TAX-MOCK', 'fullName' => 'Tax'],
                    'defaultNonTaxableCode' => ['listId' => 'TAX-MOCK', 'fullName' => 'Tax'],
                    'defaultTaxItem' => ['listId' => null, 'fullName' => null],
                    'multiLocationAvailable' => $mode === 'unknown-multi-location'
                        ? null
                        : ($mode === 'disabled-multi-location' ? false : true),
                    'multiLocationEnabled' => $mode === 'unknown-multi-location'
                        ? null
                        : ($mode === 'disabled-multi-location' ? false : true),
                    'lotTracking' => null,
                    'expirationDateEnabled' => false,
                    'salesTransactionTrackingEnabled' => null,
                ];
            } elseif (isset($request['searchName'])) {
                if ($mode === 'site-query-failure' && $entity === 'inventory_site') {
                    return posting_signed_response(503, [
                        'requestId' => $request['requestId'],
                        'status' => 'unavailable',
                        'diagnosticCode' => 'REFERENCE_QUERY_FAILED',
                        'failureStage' => 'reference_query',
                    ], $secret);
                }
                if (
                    ($mode === 'not-found-fullname' && in_array($entity, ['customer', 'item'], true))
                    || ($mode === 'different-item-results'
                        && $entity === 'item'
                        && $request['searchName'] === 'LOCAL-CODE')
                ) {
                    return posting_signed_response(200, [
                        'requestId' => $request['requestId'],
                        'status' => 'ok',
                        'entity' => $entity,
                        'searchName' => $request['searchName'],
                        'records' => [],
                        'companyFingerprint' => $mockFingerprint,
                        'companyIdentityVerified' => true,
                        'companyIdentity' => [
                            'companyName' => 'Mock QuickBooks Company',
                            'legalCompanyName' => 'Mock QuickBooks Company LLC',
                        ],
                        'qbxmlVersion' => '16.0',
                    ], $secret);
                }
                if ($mode === 'site-not-found' && $entity === 'inventory_site') {
                    return posting_signed_response(200, [
                        'requestId' => $request['requestId'],
                        'status' => 'ok',
                        'entity' => $entity,
                        'searchName' => $request['searchName'],
                        'records' => [],
                        'companyFingerprint' => $mockFingerprint,
                        'companyIdentityVerified' => true,
                        'companyIdentity' => [
                            'companyName' => 'Mock QuickBooks Company',
                            'legalCompanyName' => 'Mock QuickBooks Company LLC',
                        ],
                        'qbxmlVersion' => '16.0',
                    ], $secret);
                }
                $records[] = [
                    'listId' => 'SITE-MOCK',
                    'name' => $entity === 'inventory_site' ? 'Main Site' : $request['searchName'],
                    'fullName' => $entity === 'inventory_site' && $mode === 'bad-site'
                        ? 'Other Site'
                        : $request['searchName'],
                    'active' => ($entity === 'inventory_site' && $mode === 'inactive-site')
                        || (in_array($entity, ['customer', 'item'], true) && $mode === 'inactive-fullname')
                        || ($mode === 'different-item-results'
                            && $entity === 'item'
                            && $request['searchName'] === 'SECOND-LOCAL-CODE')
                        ? false
                        : ($mode === 'bad-site'
                            ? true
                            : ($entity === 'inventory_site' && $mode === 'unknown-site-active' ? null : true)),
                    'found' => true,
                ];
                if ($entity === 'customer') {
                    $records[0]['companyName'] = null;
                } elseif ($entity === 'item') {
                    $records[0]['manufacturerPartNumber'] = null;
                    $records[0]['salesDescription'] = null;
                }
                if (
                    $mode === 'ambiguous-fullname'
                    && in_array($entity, ['customer', 'item'], true)
                ) {
                    $duplicate = $records[0];
                    $duplicate['listId'] = 'SITE-MOCK-2';
                    $records[] = $duplicate;
                }
            } else {
                foreach ($request['listIds'] as $listId) {
                    if ($entity === 'item_uom') {
                        $records[] = [
                            'listId' => $listId,
                            'found' => true,
                            'itemName' => 'Mock Item',
                            'active' => true,
                            'unitSetListId' => $mode === 'unit-set' ? 'UNIT-SET' : null,
                            'unitSetName' => $mode === 'unit-set' ? 'Each' : null,
                            'unitSetActive' => $mode === 'unit-set' ? true : null,
                            'unitType' => $mode === 'unit-set' ? 'Count' : null,
                            'units' => $mode === 'unit-set' ? [['name' => 'Each', 'abbreviation' => 'ea']] : [],
                            'salesDefaultUnit' => $mode === 'unit-set' ? 'Each' : null,
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
posting_assert(($counters['write_request']['transaction']['lines'][0]['pricingSource'] ?? null) === 'mfc_entered',
    'signed InvoiceAdd payload explicitly identifies the validated MFC-entered price source');
$saved = $pdo->query('SELECT status,qb_txn_id,qb_ref_number FROM qb_exports')->fetch();
posting_assert($saved['status'] === 'succeeded' && $saved['qb_txn_id'] === 'TXN-MOCK-123' && $saved['qb_ref_number'] === 'INV-MOCK', 'confirmed QuickBooks transaction ID and local reference are persisted');
posting_assert((int)$pdo->query("SELECT COUNT(*) FROM qb_export_attempts WHERE outcome='succeeded'")->fetchColumn() === 1, 'successful attempt is durably recorded and finalized');
$duplicate = qb_export_post($pdo, $exportId, 1, $bridge);
posting_assert($duplicate['outcome'] === 'blocked' && $counters['writes'] === 1, 'duplicate submission cannot produce a second mock write');

$pdo = posting_fixture();
$pdo->exec("UPDATE invoice_items SET price_each='0.00',amount='0.00',price_source='explicit_zero' WHERE id=1");
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$zeroPriceBridge = posting_bridge('success', $counters, $secret);
$preflight = qb_export_post_preflight($pdo, $exportId, $zeroPriceBridge);
posting_assert($preflight['ready'] === true, 'explicit zero and positive rates pass the same protected invoice preflight');
$result = qb_export_post($pdo, $exportId, 1, $zeroPriceBridge);
posting_assert($result['outcome'] === 'succeeded' && $counters['writes'] === 1,
    'mixed zero and positive invoice lines complete the mocked one-shot posting workflow');
posting_assert(($counters['write_request']['transaction']['lines'][0]['rate'] ?? null) === '0.00'
    && ($counters['write_request']['transaction']['lines'][0]['expectedAmount'] ?? null) === '0.00'
    && ($counters['write_request']['transaction']['lines'][0]['pricingSource'] ?? null) === 'explicit_zero'
    && ($counters['write_request']['transaction']['lines'][1]['rate'] ?? null) === '5.00',
    'signed posting payload preserves the explicit zero rate, amount, source, and following positive line');
$duplicate = qb_export_post($pdo, $exportId, 1, $zeroPriceBridge);
posting_assert($duplicate['outcome'] === 'blocked' && $counters['writes'] === 1,
    'duplicate protection remains active after a zero-priced invoice succeeds');

$pdo = posting_fixture();
$legacyPreview = qb_export_validate($pdo, 'invoice', 1, null, 'legacy_mapped');
$legacyExportId = qb_export_create(
    $pdo,
    'invoice',
    1,
    $legacyPreview['source_revision'],
    1,
    'legacy_mapped'
)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$legacyResult = qb_export_post($pdo, $legacyExportId, 1, posting_bridge('success', $counters, $secret));
posting_assert($legacyResult['outcome'] === 'succeeded'
    && ($counters['write_request']['preparationMode'] ?? null) === 'legacy_mapped'
    && ($counters['write_request']['transaction']['lines'][0]['salesTaxCode']['listId'] ?? null) === 'TAX-MOCK',
    'legacy invoice snapshots preserve explicit tax mappings in the signed submit payload');

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

$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
posting_assert($validation['valid']
    && $validation['transaction']['customer_full_name'] === 'Mock Customer'
    && $validation['transaction']['lines'][0]['item_full_name'] === 'LOCAL-CODE'
    && $validation['transaction']['customer_list_id'] === null
    && $validation['transaction']['lines'][0]['item_list_id'] === null,
    'unmapped invoice customer and item use separate exact FullName candidates without treating Item Code as a ListID');

$pdo = posting_fixture();
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$fullnameBridge = posting_bridge('success', $counters, $secret);
$preflight = qb_export_post_preflight($pdo, $exportId, $fullnameBridge);
posting_assert($preflight['ready'] === true,
    'company-bound read-only preflight accepts unique active exact FullName references');
$result = qb_export_post($pdo, $exportId, 1, $fullnameBridge);
posting_assert($result['outcome'] === 'succeeded'
&& ($counters['write_request']['preparationMode'] ?? null) === 'quickbooks_managed_defaults'
&& ($counters['write_request']['transaction']['customer']['fullName'] ?? null) === 'Mock Customer'
    && count($counters['write_request']['transaction']['lines']) === 2
    && ($counters['write_request']['transaction']['lines'][0]['item']['fullName'] ?? null) === 'LOCAL-CODE'
    && ($counters['write_request']['transaction']['lines'][1]['item']['fullName'] ?? null) === 'LOCAL-CODE'
    && ($counters['write_request']['transaction']['lines'][0]['site'] ?? null) === 'Main Site'
    && !array_key_exists('salesTaxCode', $counters['write_request']['transaction']['lines'][0]),
    'the immutable FullName-mode invoice reaches the gated submit path with managed tax omission');

foreach (['null-customer-tax-pref', 'false-customer-tax-pref'] as $taxPreferenceMode) {
    $pdo = posting_fixture(true);
    $pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
    $pdo->exec('UPDATE clients SET qb_list_id=NULL');
    $pdo->exec('UPDATE products SET qb_list_id=NULL');
    $validation = qb_export_validate($pdo, 'invoice', 1);
    $exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
    $counters = ['writes' => 0, 'diagnostics' => 0];
    $preflight = qb_export_post_preflight($pdo, $exportId, posting_bridge($taxPreferenceMode, $counters, $secret));
    posting_assert($preflight['ready'] === true && $counters['writes'] === 0,
        'unknown or disabled customer-tax-code preference does not block independently verified invoice references');
}

$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$unknownSitePreference = qb_export_post_preflight($pdo, $exportId, posting_bridge('unknown-multi-location', $counters, $secret));
posting_assert($unknownSitePreference['ready'] === false
    && in_array('INVENTORY_SITE_PREFERENCE_UNKNOWN', array_column($unknownSitePreference['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'unknown multiple-location preference remains a specific invoice-preflight blocker');
$disabledSitePreference = qb_export_post_preflight($pdo, $exportId, posting_bridge('disabled-multi-location', $counters, $secret));
posting_assert($disabledSitePreference['ready'] === false
    && in_array('INVENTORY_SITES_DISABLED', array_column($disabledSitePreference['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'verified disabled multiple-location preference blocks invoice preflight');

$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$ambiguousPreflight = qb_export_post_preflight($pdo, $exportId, posting_bridge('ambiguous-fullname', $counters, $secret));
posting_assert($ambiguousPreflight['ready'] === false
    && in_array('CUSTOMER_FULLNAME_AMBIGUOUS', array_column($ambiguousPreflight['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'duplicate exact FullName records block posting before any submit request');

$pdo = posting_fixture(true, true);
posting_assert(posting_has_error(qb_export_validate($pdo, 'invoice', 1), 'EXPIRATION_DESTINATION_UNVERIFIED'),
    'unsupported expiration date mapping blocks posting before submission');

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$badMappings = posting_bridge('bad-site', $counters, $secret);
$preflight = qb_export_post_preflight($pdo, $exportId, $badMappings);
posting_assert($preflight['ready'] === false
    && in_array('SITE_FULLNAME_MISMATCH', array_column($preflight['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'a returned but mismatched inventory-site FullName is distinguished from a failed lookup');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$siteNotFound = qb_export_post_preflight($pdo, $exportId, posting_bridge('site-not-found', $counters, $secret));
posting_assert($siteNotFound['ready'] === false
    && in_array('SITE_NOT_FOUND', array_column($siteNotFound['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'an empty successful inventory-site query is reported as not found');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$inactiveSite = qb_export_post_preflight($pdo, $exportId, posting_bridge('inactive-site', $counters, $secret));
posting_assert($inactiveSite['ready'] === false
    && in_array('SITE_INACTIVE', array_column($inactiveSite['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'an exact inactive inventory Site is distinguished from a failed lookup');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$unknownSiteActive = qb_export_post_preflight($pdo, $exportId, posting_bridge('unknown-site-active', $counters, $secret));
posting_assert($unknownSiteActive['ready'] === false
    && in_array('SITE_ACTIVE_STATUS_UNKNOWN', array_column($unknownSiteActive['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'an unknown inventory Site active status remains a specific blocker');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$siteQueryFailed = qb_export_post_preflight($pdo, $exportId, posting_bridge('site-query-failure', $counters, $secret));
posting_assert($siteQueryFailed['ready'] === false
    && in_array('SITE_QUERY_FAILED', array_column($siteQueryFailed['errors'], 'code'), true)
    && !in_array('SITE_NOT_FOUND', array_column($siteQueryFailed['errors'], 'code'), true)
    && !in_array('SITE_INACTIVE', array_column($siteQueryFailed['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'inventory-site query failures are not mislabeled as missing or inactive sites');
$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$inactiveFullName = qb_export_post_preflight($pdo, $exportId, posting_bridge('inactive-fullname', $counters, $secret));
posting_assert($inactiveFullName['ready'] === false
    && in_array('CUSTOMER_FULLNAME_INACTIVE', array_column($inactiveFullName['errors'], 'code'), true)
    && in_array('ITEM_FULLNAME_INACTIVE', array_column($inactiveFullName['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'exact inactive customer and item FullNames are distinguished from failed queries');
$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$notFoundFullName = qb_export_post_preflight($pdo, $exportId, posting_bridge('not-found-fullname', $counters, $secret));
posting_assert($notFoundFullName['ready'] === false
    && in_array('CUSTOMER_FULLNAME_NOT_FOUND', array_column($notFoundFullName['errors'], 'code'), true)
    && in_array('ITEM_FULLNAME_NOT_FOUND', array_column($notFoundFullName['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'empty successful customer and item FullName queries are reported as not found');
$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$pdo->exec("INSERT INTO products VALUES(2,'Second Mock Product',NULL,'SECOND-CODE','SECOND-CODE','Second Mock Item','Second Mock Item','',1)");
$pdo->exec("INSERT INTO invoice_items VALUES
    (3,1,NULL,'WITHDRAWAL',2,1,'Second Mock Product',1,'','SECOND-LOCAL-CODE','Second mock line','','3.00','3.00','mfc_entered','Tax',NULL)");
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$differentItemResults = qb_export_post_preflight(
    $pdo,
    $exportId,
    posting_bridge('different-item-results', $counters, $secret)
);
$differentItemErrors = array_values(array_filter(
    $differentItemResults['errors'],
    static fn(array $error): bool => ($error['entity'] ?? null) === 'item'
));
$differentItemMessages = qb_export_preflight_summary_messages($differentItemErrors);
posting_assert($differentItemResults['ready'] === false
    && count($differentItemErrors) === 2
    && array_column($differentItemErrors, 'code') === ['ITEM_FULLNAME_NOT_FOUND', 'ITEM_FULLNAME_INACTIVE']
    && count($differentItemMessages) === 2
    && str_contains(implode(' ', $differentItemMessages), 'LOCAL-CODE')
    && str_contains(implode(' ', $differentItemMessages), 'SECOND-LOCAL-CODE')
    && $counters['writes'] === 0,
    'different per-item FullName failures remain separately actionable without a QuickBooks write');
$pdo = posting_fixture(true);
$pdo->exec("DELETE FROM qb_export_reference_verifications WHERE reference_type IN ('customer','item')");
$pdo->exec('UPDATE clients SET qb_list_id=NULL');
$pdo->exec('UPDATE products SET qb_list_id=NULL');
$validation = qb_export_validate($pdo, 'invoice', 1);
$exportId = qb_export_create($pdo, 'invoice', 1, $validation['source_revision'], 1)['export_id'];
$counters = ['writes' => 0, 'diagnostics' => 0];
$sharedQueryFailure = qb_export_post_preflight($pdo, $exportId, posting_bridge('diagnostic-failure', $counters, $secret));
$sharedErrors = array_values(array_filter($sharedQueryFailure['errors'], static fn(array $error): bool => !empty($error['shared_failure'])));
$summaryMessages = qb_export_preflight_summary_messages($sharedErrors);
posting_assert($sharedQueryFailure['ready'] === false
    && count($sharedErrors) >= 3
    && count($summaryMessages) === 1
    && str_contains($summaryMessages[0], 'SDK_COM_80040418')
    && $counters['writes'] === 0,
    'shared signed SDK failures retain per-reference details while the summary reports one root cause');
$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$badTax = posting_bridge('bad-tax', $counters, $secret);
$preflight = qb_export_post_preflight($pdo, $exportId, $badTax);
posting_assert($preflight['ready'] === false
    && in_array('TAX_DEFAULTS_UNVERIFIED', array_column($preflight['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'inactive company-level tax defaults block managed-defaults posting without per-line tax mappings');

$pdo = posting_fixture(true, false, true);
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$unitBridge = posting_bridge('unit-set', $counters, $secret);
$preflight = qb_export_post_preflight($pdo, $exportId, $unitBridge);
posting_assert($preflight['ready'] === true, 'verified item sales unit is accepted after live unit-set preflight');

$pdo = posting_fixture();
$exportId = posting_create_export($pdo);
$counters = ['writes' => 0, 'diagnostics' => 0];
$unitRequiredBridge = posting_bridge('unit-set', $counters, $secret);
$preflight = qb_export_post_preflight($pdo, $exportId, $unitRequiredBridge);
posting_assert($preflight['ready'] === false
    && in_array('UNIT_OF_MEASURE_REQUIRED', array_column($preflight['errors'], 'code'), true)
    && $counters['writes'] === 0,
    'unit-set items without a supplied sales unit are blocked to protect quantity meaning');

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
