<?php

declare(strict_types=1);
require_once __DIR__ . '/../backend/core/quickbooks_exports.php';
require_once __DIR__ . '/../backend/core/quickbooks_bridge_client.php';
require_once __DIR__ . '/../backend/core/functions.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

function test_has_error(array $result, string $code): bool
{
    foreach ($result['errors'] as $error) {
        if ($error['code'] === $code) {
            return true;
        }
    }
    return false;
}

function test_add_verification(PDO $pdo, string $type, string $key, string $listId): void
{
    $record = [
        'reference_type' => $type,
        'local_key' => $key,
        'qb_list_id' => $listId,
        'qb_full_name' => 'Verified test display name',
        'active' => 1,
        'company_file_fingerprint' => str_repeat('b', 64),
        'diagnostic_request_id' => qb_export_uuid(),
        'verified_at' => '2020-01-01 12:00:00',
        'verified_by' => 1,
    ];
    $record['evidence_hmac'] = qb_export_verification_signature($record, str_repeat('v', 40));
    $insert = $pdo->prepare(
        'INSERT INTO qb_export_reference_verifications
         (reference_type,local_key,qb_list_id,qb_full_name,verified_at,verified_by,
          company_file_fingerprint,diagnostic_request_id,evidence_hmac,active)
         VALUES (?,?,?,?,?,?,?,?,?,1)'
    );
    $insert->execute([
        $record['reference_type'], $record['local_key'], $record['qb_list_id'],
        'Verified test display name', $record['verified_at'], $record['verified_by'],
        $record['company_file_fingerprint'], $record['diagnostic_request_id'],
        $record['evidence_hmac'],
    ]);
}

function test_signed_response(int $status, string $body, string $requestId, string $secret, ?int $timestamp = null): array
{
    $timestamp ??= time();
    return [
        'status' => $status,
        'body' => $body,
        'headers' => [
            'x-mfc-timestamp' => (string)$timestamp,
            'x-mfc-signature' => hash_hmac(
                'sha256',
                $timestamp . "\nresponse\n" . $requestId . "\n" . $status . "\n" . hash('sha256', $body),
                $secret
            ),
        ],
    ];
}

function test_database(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, client_name TEXT, qb_list_id TEXT, active INTEGER)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, product_name TEXT, qb_list_id TEXT, qb_code TEXT, qb_description TEXT, description TEXT, u_m TEXT, active INTEGER)');
    $pdo->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY, site_name TEXT, active INTEGER)');
    $pdo->exec('CREATE TABLE withdrawals (id INTEGER PRIMARY KEY, withdrawal_no TEXT)');
    $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, withdrawal_id INTEGER, customer_id INTEGER, invoice_number TEXT, invoice_date TEXT, po_no TEXT, memo TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE invoice_items (id INTEGER PRIMARY KEY, invoice_id INTEGER, withdrawal_item_id INTEGER, source_type TEXT, product_id INTEGER, site_id INTEGER, product_name TEXT, quantity INTEGER, lot_number TEXT, item_code TEXT, description TEXT, u_m TEXT, price_each TEXT, amount TEXT, tax_code TEXT, expiration_date TEXT)');
    $pdo->exec('CREATE TABLE sales_orders (id INTEGER PRIMARY KEY, withdrawal_id INTEGER, customer_id INTEGER, qb_customer_list_id TEXT, so_number TEXT, sales_order_date TEXT, po_no TEXT, memo TEXT, status TEXT)');
    $pdo->exec('CREATE TABLE sales_order_items (id INTEGER PRIMARY KEY, sales_order_id INTEGER, line_no INTEGER, product_id INTEGER, site_id INTEGER, item_name TEXT, ordered_quantity INTEGER, lot_number TEXT, item_code TEXT, description TEXT, u_m TEXT, rate TEXT, amount TEXT, tax_code TEXT, expiration_date TEXT, class_name TEXT, line_status TEXT, fulfilled_quantity INTEGER, qb_item_list_id TEXT)');
    $pdo->exec('CREATE TABLE qb_jobs (id INTEGER PRIMARY KEY, invoice_id INTEGER)');
    $pdo->exec('CREATE TABLE employees (id INTEGER PRIMARY KEY, role TEXT, active INTEGER)');
    $pdo->exec('CREATE TABLE qb_export_reference_verifications (reference_type TEXT, local_key TEXT, qb_list_id TEXT, qb_full_name TEXT, verified_at TEXT, verified_by INTEGER, company_file_fingerprint TEXT, diagnostic_request_id TEXT, evidence_hmac TEXT, active INTEGER)');
    $pdo->exec('CREATE TABLE qb_exports (export_id TEXT PRIMARY KEY, transaction_type TEXT, source_transaction_id INTEGER, source_revision TEXT, snapshot_json TEXT, status TEXT, attempt_count INTEGER DEFAULT 0, created_by INTEGER, qb_txn_id TEXT, qb_ref_number TEXT, claim_token TEXT, lease_until TEXT, updated_at TEXT, completed_at TEXT, UNIQUE(transaction_type, source_transaction_id))');
    $pdo->exec('CREATE TABLE qb_export_attempts (export_id TEXT, attempt_number INTEGER, request_id TEXT UNIQUE, outcome TEXT, started_at TEXT, completed_at TEXT, diagnostic_code TEXT, diagnostic_message TEXT, UNIQUE(export_id, attempt_number))');
    $pdo->exec('CREATE TABLE qb_export_events (export_id TEXT, event_type TEXT, from_status TEXT, to_status TEXT, actor_id INTEGER, request_id TEXT, diagnostic_code TEXT)');
    $pdo->exec('CREATE TABLE activity_logs (employee_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, details TEXT, ip_address TEXT, created_at TEXT)');

    $pdo->exec("INSERT INTO clients VALUES (1,'Example Customer','CUST-LIST-1',1)");
    $pdo->exec("INSERT INTO products VALUES (1,'Example Product','ITEM-LIST-1','ITEM-CODE-1','QB description','Product description','BOX',1)");
    $pdo->exec("INSERT INTO sites VALUES (1,'Example Site',1)");
    $pdo->exec("INSERT INTO withdrawals VALUES (1,'W-1')");
    $pdo->exec("INSERT INTO invoices VALUES (1,1,1,'INV-1','2026-10-23','PO-1',NULL,'Draft')");
    $pdo->exec("INSERT INTO invoice_items VALUES (1,1,NULL,'WITHDRAWAL',1,1,'Example Product',2,'LOT-1','QB-1','Approved description','BOX','10.00','20.00','Tax','2026-12-31')");
    $pdo->exec('INSERT INTO employees VALUES (2,"Employee",1)');
    $pdo->exec("INSERT INTO sales_orders VALUES (1,1,1,'CUST-LIST-1','SO-1','2026-10-23','PO-1',NULL,'Draft')");
    $pdo->exec("INSERT INTO sales_order_items VALUES (1,1,1,1,1,'Example Product',2,'','QB-1','Approved description','', '10.00','20.00','Tax',NULL,NULL,NULL,NULL,'ITEM-LIST-1')");
    $pdo->exec("INSERT INTO employees VALUES (1,'Admin',1)");
    return $pdo;
}

try {
    putenv('WQ_QB_VERIFICATION_SECRET=' . str_repeat('v', 40));
    putenv('WQ_QB_COMPANY_FINGERPRINT=' . str_repeat('b', 64));
    $pdo = test_database();

    test_assert(qb_export_is_authorized(['role' => 'Admin']), 'administrator can manage export previews');
    test_assert(!qb_export_is_authorized(['role' => 'Employee']), 'employee cannot manage export previews');
    test_assert(csrf_token_matches('session-token', 'session-token'), 'CSRF validation accepts the current token');
    test_assert(!csrf_token_matches('session-token', 'attacker-token'), 'CSRF validation rejects a mismatched token');
    test_assert(!csrf_token_matches('', ''), 'CSRF validation rejects an absent session token');

    $invoice = qb_export_validate($pdo, 'invoice', 1);
    test_assert(!$invoice['valid'] && $invoice['transaction']['transaction_type'] === 'invoice', 'invoice preview is explicitly typed');
    test_assert(test_has_error($invoice, 'CUSTOMER_MAPPING_UNVERIFIED'), 'missing verified customer mapping blocks export');
    test_assert(test_has_error($invoice, 'ITEM_MAPPING_UNVERIFIED'), 'missing verified item mapping blocks export');
    test_assert(test_has_error($invoice, 'TAX_MAPPING_UNVERIFIED'), 'missing approved tax mapping blocks export');
    test_assert(test_has_error($invoice, 'SITE_DESTINATION_UNVERIFIED'), 'unresolved Site destination blocks export');
    test_assert(test_has_error($invoice, 'LOT_DESTINATION_UNVERIFIED'), 'unresolved lot field blocks export');
    test_assert(test_has_error($invoice, 'EXPIRATION_DESTINATION_UNVERIFIED'), 'unresolved expiration field blocks export');
    test_assert(test_has_error($invoice, 'ITEM_CODE_DESTINATION_UNVERIFIED'), 'local Item Code is not silently substituted for the QuickBooks item reference');
    test_assert(test_has_error($invoice, 'TAX_TOTAL_UNRESOLVED'), 'unreconciled QuickBooks tax and transaction total block export');
    test_assert(test_has_error($invoice, 'LEGACY_JOB_EXISTS') === false, 'invoice export is independent when no legacy qb_jobs record exists');
    test_assert($invoice['transaction']['expected_subtotal'] === '20.00', 'expected invoice subtotal is calculated from quantity and rate');
    test_assert($invoice['transaction']['tax_total'] === null && $invoice['transaction']['expected_total'] === null, 'tax and total reconciliation remain explicitly unresolved');
    $bridgeRequestRejected = false;
    try {
        qb_export_bridge_request($invoice);
    } catch (DomainException) {
        $bridgeRequestRejected = true;
    }
    test_assert($bridgeRequestRejected, 'unresolved preview data cannot be silently dropped into a bridge request');

    $pdo->exec("UPDATE clients SET active=0 WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'CUSTOMER_INACTIVE'), 'inactive local customer blocks export');
    $pdo->exec("UPDATE clients SET active=1 WHERE id=1");
    $pdo->exec("UPDATE invoice_items SET tax_code='Other' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'INVALID_LOCAL_TAX_CODE'), 'unsupported local tax labels are rejected');
    $pdo->exec("UPDATE invoice_items SET tax_code='Exempt' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'INVALID_LOCAL_TAX_CODE'), 'Exempt is rejected because it is not an invoice schema or UI value');
    $pdo->exec("UPDATE invoice_items SET tax_code='Tax' WHERE id=1");
    $pdo->exec("UPDATE sales_order_items SET tax_code='Exempt' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'sales_order', 1), 'INVALID_LOCAL_TAX_CODE'), 'legacy Exempt sales-order values are preserved but blocked');
    $pdo->exec("UPDATE sales_order_items SET tax_code='Tax' WHERE id=1");

    $pdo->exec(
        "INSERT INTO qb_export_reference_verifications
         (reference_type,local_key,qb_list_id,qb_full_name,verified_at,verified_by,
          company_file_fingerprint,diagnostic_request_id,evidence_hmac,active)
         VALUES('customer','client:1','CUST-LIST-1','Example Customer','2020-01-01 12:00:00',
          1,'" . str_repeat('b', 64) . "','" . qb_export_uuid() . "',NULL,1)"
    );
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'CUSTOMER_MAPPING_UNVERIFIED'), 'an unsigned arbitrary verification row cannot approve a ListID');
    $pdo->exec('DELETE FROM qb_export_reference_verifications');
    test_add_verification($pdo, 'customer', 'client:1', 'CUST-LIST-1');
    test_add_verification($pdo, 'item', 'product:1', 'ITEM-LIST-1');
    test_add_verification($pdo, 'tax_code', 'tax:Tax', 'TAX-LIST-1');
    $verifiedPreview = qb_export_validate($pdo, 'invoice', 1);
    test_assert(!test_has_error($verifiedPreview, 'CUSTOMER_MAPPING_UNVERIFIED')
        && !test_has_error($verifiedPreview, 'ITEM_MAPPING_UNVERIFIED')
        && !test_has_error($verifiedPreview, 'TAX_MAPPING_UNVERIFIED'), 'signed evidence from an active Admin approves exact customer, item, and tax references');
    putenv('WQ_QB_COMPANY_FINGERPRINT=' . str_repeat('c', 64));
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'CUSTOMER_MAPPING_UNVERIFIED'), 'a verification for another QuickBooks company fingerprint is stale');
    putenv('WQ_QB_COMPANY_FINGERPRINT=' . str_repeat('b', 64));
    $pdo->exec('UPDATE employees SET active=0 WHERE id=1');
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'ITEM_MAPPING_UNVERIFIED'), 'inactive Admin approvers cannot authorize mappings');
    $pdo->exec('UPDATE employees SET active=1 WHERE id=1');

    $pdo->exec("UPDATE qb_export_reference_verifications SET qb_list_id='ITEM-LIST-OTHER' WHERE reference_type='item'");
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'ITEM_MAPPING_UNVERIFIED'), 'modified verification records fail their evidence signature');
    $pdo->exec("UPDATE qb_export_reference_verifications SET qb_list_id='ITEM-LIST-1' WHERE reference_type='item'");
    $mappingRevision = qb_export_validate($pdo, 'invoice', 1)['source_revision'];
    $pdo->exec("UPDATE clients SET qb_list_id='CUST-LIST-2' WHERE id=1");
    $changedCustomerMapping = qb_export_validate($pdo, 'invoice', 1);
    test_assert(test_has_error($changedCustomerMapping, 'CUSTOMER_MAPPING_UNVERIFIED')
        && $changedCustomerMapping['source_revision'] !== $mappingRevision, 'changed customer ListID invalidates verification and changes the source revision');
    $pdo->exec("UPDATE clients SET qb_list_id='CUST-LIST-1' WHERE id=1");
    $productRevision = qb_export_validate($pdo, 'invoice', 1)['source_revision'];
    $pdo->exec("UPDATE products SET qb_list_id='ITEM-LIST-2' WHERE id=1");
    $changedItemMapping = qb_export_validate($pdo, 'invoice', 1);
    test_assert(test_has_error($changedItemMapping, 'ITEM_MAPPING_UNVERIFIED')
        && $changedItemMapping['source_revision'] !== $productRevision, 'changed product ListID invalidates verification and changes the source revision');
    $pdo->exec("UPDATE products SET qb_list_id='ITEM-LIST-1',qb_description='Updated QB description' WHERE id=1");
    $updatedProductDescription = qb_export_validate($pdo, 'invoice', 1);
    test_assert($updatedProductDescription['source_revision'] !== $productRevision, 'current product descriptions are included in the source revision');
    $pdo->exec("UPDATE products SET qb_description='QB description' WHERE id=1");

    $unauthorizedQueueCreation = false;
    try {
        qb_export_create($pdo, 'invoice', 1, $verifiedPreview['source_revision'], 2);
    } catch (DomainException) {
        $unauthorizedQueueCreation = true;
    }
    test_assert($unauthorizedQueueCreation, 'server-side queue creation rejects non-Admin users');
    test_assert((int)$pdo->query('SELECT COUNT(*) FROM qb_exports')->fetchColumn() === 0, 'unauthorized queue creation writes no export');
    $pdo->exec('INSERT INTO qb_jobs(invoice_id) VALUES(1)');
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'LEGACY_JOB_EXISTS'), 'legacy invoice qb_jobs records continue to block duplicate typed export');
    $pdo->exec('DELETE FROM qb_jobs WHERE invoice_id=1');
    $pdo->exec("UPDATE invoice_items SET tax_code='Tax' WHERE id=1");
    $pdo->exec("UPDATE invoice_items SET quantity=999999999, price_each='9999999999.99', amount='999999999.00' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'invoice', 1), 'AMOUNT_OUT_OF_RANGE'), 'calculated line amount overflow is rejected');
    $pdo->exec("UPDATE invoice_items SET quantity=2, price_each='10.00', amount='20.00' WHERE id=1");

    $order = qb_export_validate($pdo, 'sales_order', 1);
    test_assert($order['transaction']['transaction_type'] === 'sales_order', 'sales-order preview remains separate from invoices');
    test_assert(!test_has_error($order, 'CUSTOMER_MAPPING_UNVERIFIED'), 'sales-order customer mapping is verified independently');
    $pdo->exec("UPDATE sales_order_items SET class_name='LOCAL-CLASS' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'sales_order', 1), 'CLASS_DESTINATION_UNVERIFIED'), 'unresolved sales-order class values block export');
    $pdo->exec("UPDATE sales_order_items SET class_name=NULL,line_status='Open' WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'sales_order', 1), 'LINE_STATUS_DESTINATION_UNVERIFIED'), 'unresolved sales-order line status values block export');
    $pdo->exec("UPDATE sales_order_items SET line_status=NULL,fulfilled_quantity=1 WHERE id=1");
    test_assert(test_has_error(qb_export_validate($pdo, 'sales_order', 1), 'FULFILLED_QUANTITY_DESTINATION_UNVERIFIED'), 'unresolved fulfilled quantity values block export');
    $pdo->exec('UPDATE sales_order_items SET fulfilled_quantity=NULL WHERE id=1');

    $pdo->exec("UPDATE invoice_items SET amount='21.00' WHERE id=1");
    $invalidAmount = qb_export_validate($pdo, 'invoice', 1);
    test_assert(test_has_error($invalidAmount, 'AMOUNT_MISMATCH'), 'invalid saved amount is rejected');
    $pdo->exec("UPDATE invoice_items SET amount='20.00' WHERE id=1");

    $oldRevision = qb_export_validate($pdo, 'invoice', 1)['source_revision'];
    $uuid = qb_export_uuid();
    $insert = $pdo->prepare('INSERT INTO qb_exports (export_id,transaction_type,source_transaction_id,source_revision,snapshot_json,status,created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([$uuid, 'invoice', 1, $oldRevision, '{}', 'pending', 1]);
    $pendingRecheck = qb_export_revalidate_record($pdo, $uuid);
    $duplicateErrorReturned = false;
    foreach ($pendingRecheck['errors'] ?? [] as $error) {
        if ($error['code'] === 'EXPORT_ALREADY_EXISTS') $duplicateErrorReturned = true;
    }
    test_assert(!$duplicateErrorReturned, 'revalidation excludes the export record being revalidated');
    $pdo->exec("UPDATE clients SET qb_list_id='CUST-LIST-CHANGED' WHERE id=1");
    test_assert(qb_export_revalidate_record($pdo, $uuid)['code'] === 'SOURCE_CHANGED', 'customer mapping changes after snapshot require reconciliation');
    $pdo->exec("UPDATE clients SET qb_list_id='CUST-LIST-1' WHERE id=1");
    $pdo->exec("UPDATE invoice_items SET quantity=3,amount='30.00' WHERE id=1");
    test_assert(qb_export_revalidate_record($pdo, $uuid)['code'] === 'SOURCE_CHANGED', 'line quantity and amount changes after snapshot require reconciliation');
    $pdo->exec("UPDATE invoice_items SET quantity=2,amount='20.00' WHERE id=1");
    $duplicate = qb_export_validate($pdo, 'invoice', 1);
    test_assert(test_has_error($duplicate, 'EXPORT_ALREADY_EXISTS'), 'duplicate source transaction is blocked by application validation');
    try {
        $insert->execute([qb_export_uuid(), 'invoice', 1, $oldRevision, '{}', 'pending', 1]);
        test_assert(false, 'database unique constraint prevents duplicate source export');
    } catch (PDOException) {
        test_assert(true, 'database unique constraint prevents duplicate source export');
    }

    $pdo->exec("UPDATE invoices SET status='Draft' WHERE id=1");
    $oldRevision = qb_export_validate($pdo, 'invoice', 1)['source_revision'];
    $pdo->prepare('UPDATE qb_exports SET source_revision=? WHERE export_id=?')->execute([$oldRevision, $uuid]);
    $pdo->exec("UPDATE invoices SET po_no='PO-CHANGED' WHERE id=1");
    $changed = qb_export_revalidate_record($pdo, $uuid);
    test_assert($changed['code'] === 'SOURCE_CHANGED', 'source changes after snapshot require revalidation');
    qb_export_record_attempt($pdo, $uuid, 1, qb_export_uuid(), 'bridge_unavailable', 'BRIDGE_DOWN', 'token=must-not-be-saved');
    $diagnostic = $pdo->query('SELECT diagnostic_message FROM qb_export_attempts')->fetchColumn();
    test_assert($diagnostic === 'token=[REDACTED]', 'attempt diagnostics redact credential-like values');
    test_assert((int)$pdo->query('SELECT attempt_count FROM qb_exports WHERE export_id=' . $pdo->quote($uuid))->fetchColumn() === 1, 'attempt history increments the export attempt count');
    $pdo->prepare("UPDATE qb_exports SET status='processing',claim_token='claim-token' WHERE export_id=?")->execute([$uuid]);
    $unknownRequest = qb_export_uuid();
    qb_export_record_attempt($pdo, $uuid, 2, $unknownRequest, 'unknown', 'OUTCOME_UNKNOWN', '<?xml version="1.0"?><QBXML>private</QBXML>');
    $xmlDiagnostic = $pdo->query('SELECT diagnostic_message FROM qb_export_attempts ORDER BY attempt_number DESC')->fetchColumn();
    test_assert($xmlDiagnostic === 'QuickBooks XML details omitted from diagnostics.', 'QuickBooks XML is omitted from attempt diagnostics');
    test_assert(qb_export_transition($pdo, $uuid, 'claim-token', 'processing', 'unknown', null, null, 1, $unknownRequest, 'OUTCOME_UNKNOWN'), 'processing can transition to unknown with an audit event');
    test_assert((string)$pdo->query('SELECT status FROM qb_exports WHERE export_id=' . $pdo->quote($uuid))->fetchColumn() === 'unknown'
        && (int)$pdo->query('SELECT COUNT(*) FROM qb_export_events')->fetchColumn() === 1, 'unknown state is persisted and audited');
    $retryAttemptRejected = false;
    try {
        qb_export_record_attempt($pdo, $uuid, 3, qb_export_uuid(), 'dispatched', 'RETRY', '');
    } catch (DomainException) {
        $retryAttemptRejected = true;
    }
    test_assert($retryAttemptRejected, 'attempt history rejects automatic retry after unknown outcome');
    $invalidTerminalTransition = false;
    try {
        qb_export_transition($pdo, $uuid, 'claim-token', 'unknown', 'processing');
    } catch (DomainException) {
        $invalidTerminalTransition = true;
    }
    test_assert($invalidTerminalTransition, 'terminal unknown records cannot transition back to processing');

    test_assert(qb_export_allowed_transition('pending', 'processing'), 'pending export may be claimed once');
    test_assert(qb_export_allowed_transition('processing', 'unknown'), 'ambiguous processing outcome can be recorded');
    test_assert(!qb_export_allowed_transition('unknown', 'processing'), 'unknown outcome cannot be retried automatically');
    test_assert(!qb_export_allowed_transition('succeeded', 'processing'), 'successful export cannot be resubmitted');

    $unconfigured = (new QuickBooksBridgeClient('http://127.0.0.1:8765', null))->health();
    test_assert(!$unconfigured['available'] && $unconfigured['diagnostic_code'] === 'BRIDGE_NOT_CONFIGURED', 'missing bridge secret is reported unavailable');
    $secret = str_repeat('s', 40);
    $authFailure = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'error',
                'diagnosticCode' => 'AUTHENTICATION_FAILED',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(401, $responseBody, $request['requestId'], $secret);
        }
    );
    $authResult = $authFailure->health();
    test_assert($authResult['diagnostic_code'] === 'AUTHENTICATION_FAILED', 'signed bridge authentication failure is sanitized and explicit');

    $callerRequestId = qb_export_uuid();
    $sentRequestId = '';
    $requestSignatureValid = false;
    $validationClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body, array $headers) use ($secret, &$sentRequestId, &$requestSignatureValid): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $sentRequestId = $request['requestId'];
            $headerMap = [];
            foreach ($headers as $header) {
                [$name, $value] = explode(':', $header, 2);
                $headerMap[strtolower($name)] = trim($value);
            }
            $expectedRequestSignature = hash_hmac(
                'sha256',
                $headerMap['x-mfc-timestamp'] . "\nvalidate\n" . $sentRequestId . "\n" . hash('sha256', $body),
                $secret
            );
            $requestSignatureValid = hash_equals($expectedRequestSignature, $headerMap['x-mfc-signature']);
            $responseBody = json_encode(['requestId' => $request['requestId'], 'valid' => false], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $request['requestId'], $secret);
        }
    );
    $validationResult = $validationClient->validate([
        'protocolVersion' => '1',
        'requestId' => $callerRequestId,
        'operation' => 'validate',
        'validationOnly' => true,
        'idempotencyKey' => null,
        'transactionType' => 'invoice',
        'source' => ['id' => 1, 'revision' => str_repeat('a', 64)],
        'transaction' => [
            'customer' => ['listId' => 'CUST-LIST-1'],
            'txnDate' => '2026-10-23',
            'refNumber' => 'INV-1',
            'poNumber' => null,
            'memo' => null,
            'lines' => [['item' => ['listId' => 'ITEM-LIST-1'], 'description' => 'Item', 'quantity' => '2', 'rate' => '10.00', 'expectedAmount' => '20.00', 'salesTaxCode' => ['listId' => 'TAX-LIST-1']]],
            'expectedSubtotal' => '20.00',
            'expectedTax' => null,
            'expectedTotal' => null,
        ],
    ]);
    test_assert($validationResult['available'] && $validationResult['response']['valid'] === false, 'bridge validation-only requests return a matched response without posting');
    test_assert($sentRequestId !== $callerRequestId && $validationResult['request_id'] === $sentRequestId, 'bridge requests generate a fresh signed nonce instead of reusing caller request IDs');
    test_assert($requestSignatureValid, 'bridge request HMAC covers timestamp, operation, request ID, and body digest');

    $diagnosticRequest = null;
    $diagnosticClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body, array $headers) use ($secret, &$diagnosticRequest): array {
            $diagnosticRequest = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $diagnosticRequest['requestId'],
                'status' => 'ok',
                'entity' => 'customer',
                'records' => [['listId' => 'CUST-LIST-1', 'name' => 'Example Customer', 'active' => true, 'found' => true]],
                'companyFingerprint' => str_repeat('a', 64),
                'qbxmlVersion' => '16.0',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(200, $responseBody, $diagnosticRequest['requestId'], $secret);
        }
    );
    $diagnosticResult = $diagnosticClient->diagnose('customer', ['CUST-LIST-1']);
    test_assert($diagnosticResult['available']
        && $diagnosticResult['response']['records'][0]['listId'] === 'CUST-LIST-1'
        && $diagnosticRequest['operation'] === 'diagnose'
        && $diagnosticRequest['listIds'] === ['CUST-LIST-1'], 'PHP diagnostic request matches the bounded bridge query contract');
    $diagnosticBadIdRejected = false;
    try {
        $diagnosticClient->diagnose('customer', ['customer name']);
    } catch (InvalidArgumentException) {
        $diagnosticBadIdRejected = true;
    }
    test_assert($diagnosticBadIdRejected, 'diagnostic requests reject names and invalid ListID syntax');
    $diagnosticDuplicateRejected = false;
    try {
        $diagnosticClient->diagnose('item', ['ITEM-1', 'ITEM-1']);
    } catch (InvalidArgumentException) {
        $diagnosticDuplicateRejected = true;
    }
    test_assert($diagnosticDuplicateRejected, 'diagnostic requests reject duplicate ListIDs');
    $diagnosticUnavailable = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $responseBody = json_encode([
                'requestId' => $request['requestId'],
                'status' => 'unavailable',
                'diagnosticCode' => 'QUICKBOOKS_NOT_RUNNING',
            ], JSON_THROW_ON_ERROR);
            return test_signed_response(503, $responseBody, $request['requestId'], $secret);
        }
    );
    test_assert($diagnosticUnavailable->diagnose('item', ['ITEM-1'])['diagnostic_code'] === 'QUICKBOOKS_NOT_RUNNING', 'signed unavailable diagnostics preserve sanitized QuickBooks readiness codes');

    $malformedClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            return test_signed_response(200, '{broken', $request['requestId'], $secret);
        }
    );
    $malformed = $malformedClient->validate([
        'protocolVersion' => '1', 'requestId' => qb_export_uuid(), 'operation' => 'validate',
        'validationOnly' => true, 'idempotencyKey' => null, 'transactionType' => 'invoice',
        'source' => ['id' => 1, 'revision' => str_repeat('a', 64)],
        'transaction' => [
            'customer' => ['listId' => 'CUST-LIST-1'], 'txnDate' => '2026-10-23',
            'refNumber' => 'INV-1', 'poNumber' => null, 'memo' => null,
            'lines' => [['item' => ['listId' => 'ITEM-LIST-1'], 'description' => 'Item', 'quantity' => '2', 'rate' => '10.00', 'expectedAmount' => '20.00', 'salesTaxCode' => ['listId' => 'TAX-LIST-1']]],
            'expectedSubtotal' => '20.00', 'expectedTax' => null, 'expectedTotal' => null,
        ],
    ]);
    test_assert(!$malformed['available'] && $malformed['diagnostic_code'] === 'BRIDGE_INVALID_RESPONSE', 'malformed signed bridge responses are rejected');

    $oversizedClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            return test_signed_response(200, str_repeat('x', 65537), $request['requestId'], $secret);
        }
    );
    $oversized = $oversizedClient->health();
    test_assert(!$oversized['available'] && $oversized['diagnostic_code'] === 'BRIDGE_RESPONSE_TOO_LARGE', 'oversized bridge responses are rejected before JSON decoding');

    $tamperedClient = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static function (string $url, string $body) use ($secret): array {
            $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $signed = test_signed_response(200, json_encode(['requestId' => $request['requestId'], 'status' => 'ok'], JSON_THROW_ON_ERROR), $request['requestId'], $secret);
            $signed['headers']['x-mfc-signature'] = str_repeat('0', 64);
            return $signed;
        }
    );
    test_assert($tamperedClient->health()['diagnostic_code'] === 'BRIDGE_RESPONSE_AUTH_FAILED', 'tampered bridge responses fail HMAC validation');

    $transportCalled = false;
    $nonLoopbackClient = new QuickBooksBridgeClient(
        'http://example.com:8765',
        $secret,
        static function () use (&$transportCalled): array {
            $transportCalled = true;
            return ['status' => 200, 'body' => '{}'];
        }
    );
    $nonLoopback = $nonLoopbackClient->health();
    test_assert(!$nonLoopback['available']
        && $nonLoopback['diagnostic_code'] === 'BRIDGE_DESTINATION_REJECTED'
        && !$transportCalled, 'non-loopback bridge destinations are rejected before HTTP transport');

    $redirectDestination = (new QuickBooksBridgeClient('http://127.0.0.1:8765/redirect', $secret))->health();
    test_assert($redirectDestination['diagnostic_code'] === 'BRIDGE_DESTINATION_REJECTED', 'path-based redirect endpoints are rejected');
    $redirectResponse = new QuickBooksBridgeClient(
        'http://127.0.0.1:8765',
        $secret,
        static fn(): array => ['status' => 302, 'body' => '', 'headers' => ['location' => 'http://example.com']]
    );
    test_assert($redirectResponse->health()['diagnostic_code'] === 'BRIDGE_RESPONSE_AUTH_FAILED', 'unsigned redirect responses are rejected without following redirects');

    $badOperationRejected = false;
    try {
        $authFailure->validate([
            'protocolVersion' => '1',
            'requestId' => qb_export_uuid(),
            'operation' => 'export',
            'validationOnly' => false,
            'transactionType' => 'invoice',
            'source' => [],
            'transaction' => ['lines' => []],
        ]);
    } catch (InvalidArgumentException) {
        $badOperationRejected = true;
    }
    test_assert($badOperationRejected, 'bridge client rejects transaction creation operations');

    $invalidBridgeDataRejected = false;
    try {
        $invalidRequest = [
            'protocolVersion' => '1', 'requestId' => qb_export_uuid(), 'operation' => 'validate',
            'validationOnly' => true, 'idempotencyKey' => null, 'transactionType' => 'invoice',
            'source' => ['id' => 1, 'revision' => str_repeat('a', 64)],
            'unexpected' => 'not allowed',
            'transaction' => [],
        ];
        $validationClient->validate($invalidRequest);
    } catch (InvalidArgumentException) {
        $invalidBridgeDataRejected = true;
    }
    test_assert($invalidBridgeDataRejected, 'unexpected bridge request JSON fields are rejected');

    $badAmountRejected = false;
    try {
        $badAmountRequest = [
            'protocolVersion' => '1', 'requestId' => qb_export_uuid(), 'operation' => 'validate',
            'validationOnly' => true, 'idempotencyKey' => null, 'transactionType' => 'invoice',
            'source' => ['id' => 1, 'revision' => str_repeat('a', 64)],
            'transaction' => [
                'customer' => ['listId' => 'CUST-LIST-1'], 'txnDate' => '2026-10-23',
                'refNumber' => 'INV-1', 'poNumber' => null, 'memo' => null,
                'lines' => [['item' => ['listId' => 'ITEM-LIST-1'], 'description' => 'Item', 'quantity' => '2', 'rate' => '10.00', 'expectedAmount' => '21.00', 'salesTaxCode' => ['listId' => 'TAX-LIST-1']]],
                'expectedSubtotal' => '21.00', 'expectedTax' => null, 'expectedTotal' => null,
            ],
        ];
        $validationClient->validate($badAmountRequest);
    } catch (InvalidArgumentException) {
        $badAmountRejected = true;
    }
    test_assert($badAmountRejected, 'bridge validation rejects line amount and subtotal reconciliation failures');

    echo "All QuickBooks export foundation checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
