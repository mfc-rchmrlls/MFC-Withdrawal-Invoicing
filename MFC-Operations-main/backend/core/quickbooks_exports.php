<?php

declare(strict_types=1);

function qb_export_types(): array
{
    return ['invoice', 'sales_order'];
}

function qb_export_is_authorized(array $user): bool
{
    return ($user['role'] ?? '') === 'Admin';
}

function qb_export_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && $date->format('Y-m-d') === $value
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

function qb_export_money_cents(mixed $value): ?int
{
    $value = trim((string)$value);
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) {
        return null;
    }

    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $fraction = str_pad($fraction, 2, '0');
    return ((int)$whole * 100) + (int)$fraction;
}

function qb_export_money_string(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function qb_export_reference(PDO $pdo, string $type, string $localKey, ?string $listId): ?array
{
    $secret = getenv('WQ_QB_VERIFICATION_SECRET') ?: '';
    $companyFingerprint = strtolower(getenv('WQ_QB_COMPANY_FINGERPRINT') ?: '');
    if (strlen($secret) < 32
        || !preg_match('/^[a-f0-9]{64}$/D', $companyFingerprint)
        || ($type !== 'tax_code' && ($listId === null || trim($listId) === ''))) {
        return null;
    }

    $query = $pdo->prepare(
        'SELECT v.reference_type, v.local_key, v.qb_list_id, v.qb_full_name, v.active,
                v.verified_at, v.verified_by, v.company_file_fingerprint,
                v.diagnostic_request_id, v.evidence_hmac
         FROM qb_export_reference_verifications v
         JOIN employees e ON e.id=v.verified_by AND e.active=1 AND e.role="Admin"
         WHERE v.reference_type=? AND v.local_key=? AND v.active=1'
    );
    $query->execute([$type, $localKey]);
    $mapping = $query->fetch();
    if (!$mapping
        || ($listId !== null && !hash_equals((string)$mapping['qb_list_id'], trim($listId)))
        || !preg_match('/^[a-f0-9]{64}$/D', (string)$mapping['company_file_fingerprint'])
        || !qb_export_verification_is_current($mapping, $companyFingerprint)
        || !preg_match('/^[a-f0-9-]{36}$/iD', (string)$mapping['diagnostic_request_id'])
        || !preg_match('/^[a-f0-9]{64}$/D', (string)$mapping['evidence_hmac'])
        || !hash_equals(
            qb_export_verification_signature($mapping, $secret),
            (string)$mapping['evidence_hmac']
        )) {
        return null;
    }
    return $mapping;
}

function qb_export_verification_signature(array $mapping, string $secret): string
{
    $evidence = implode("\n", [
        (string)$mapping['reference_type'],
        (string)$mapping['local_key'],
        (string)$mapping['qb_list_id'],
        (string)($mapping['qb_full_name'] ?? ''),
        (string)$mapping['active'],
        (string)$mapping['company_file_fingerprint'],
        (string)$mapping['diagnostic_request_id'],
        (string)$mapping['verified_at'],
        (string)$mapping['verified_by'],
    ]);
    return hash_hmac('sha256', $evidence, $secret);
}

function qb_export_verification_is_current(array $mapping, string $expectedFingerprint): bool
{
    $verifiedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)($mapping['verified_at'] ?? ''));
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $verifiedAt !== false
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $verifiedAt <= new DateTimeImmutable()
        && hash_equals($expectedFingerprint, strtolower((string)($mapping['company_file_fingerprint'] ?? '')));
}

function qb_export_load(PDO $pdo, string $type, int $id): ?array
{
    if ($type === 'invoice') {
        $header = $pdo->prepare(
            'SELECT i.id, i.withdrawal_id, i.customer_id, i.invoice_number AS reference_number,
                    i.invoice_date AS transaction_date, i.po_no, i.memo, i.status,
                    c.client_name, c.qb_list_id AS customer_list_id, c.active AS customer_active,
                    w.withdrawal_no
             FROM invoices i
             JOIN clients c ON c.id=i.customer_id
             JOIN withdrawals w ON w.id=i.withdrawal_id
             WHERE i.id=?'
        );
        $header->execute([$id]);
        $transaction = $header->fetch();
        if (!$transaction) {
            return null;
        }

        $lineQuery = $pdo->prepare(
            'SELECT ii.id, ii.withdrawal_item_id, ii.source_type, ii.product_id, ii.site_id, ii.product_name, ii.quantity,
                    ii.lot_number, ii.item_code, ii.description, ii.u_m,
                    ii.price_each AS rate, ii.amount, ii.tax_code, ii.expiration_date,
                    p.qb_list_id AS item_list_id, p.qb_code AS current_qb_code,
                    p.qb_description AS current_qb_description, p.description AS current_product_description,
                    p.u_m AS current_product_u_m, p.active AS product_active,
                    s.site_name, s.active AS site_active
             FROM invoice_items ii
             LEFT JOIN products p ON p.id=ii.product_id
             LEFT JOIN sites s ON s.id=ii.site_id
             WHERE ii.invoice_id=?
             ORDER BY ii.id'
        );
        $lineQuery->execute([$id]);
    } elseif ($type === 'sales_order') {
        $header = $pdo->prepare(
            'SELECT so.id, so.withdrawal_id, so.customer_id, so.so_number AS reference_number,
                    so.sales_order_date AS transaction_date, so.po_no, so.memo, so.status,
                    so.qb_customer_list_id AS customer_list_snapshot,
                    c.client_name, c.qb_list_id AS customer_list_id, c.active AS customer_active,
                    w.withdrawal_no
             FROM sales_orders so
             JOIN clients c ON c.id=so.customer_id
             JOIN withdrawals w ON w.id=so.withdrawal_id
             WHERE so.id=?'
        );
        $header->execute([$id]);
        $transaction = $header->fetch();
        if (!$transaction) {
            return null;
        }

        $lineQuery = $pdo->prepare(
            'SELECT soi.id, soi.line_no, soi.product_id, soi.site_id, soi.item_name AS product_name,
                    soi.ordered_quantity AS quantity, soi.lot_number, soi.item_code,
                    soi.description, soi.u_m, soi.rate, soi.amount, soi.tax_code,
                    soi.expiration_date, soi.class_name, soi.line_status, soi.fulfilled_quantity,
                    soi.qb_item_list_id AS item_list_snapshot,
                    p.qb_list_id AS item_list_id, p.qb_code AS current_qb_code,
                    p.qb_description AS current_qb_description, p.description AS current_product_description,
                    p.u_m AS current_product_u_m, p.active AS product_active,
                    s.site_name, s.active AS site_active
             FROM sales_order_items soi
             LEFT JOIN products p ON p.id=soi.product_id
             LEFT JOIN sites s ON s.id=soi.site_id
             WHERE soi.sales_order_id=?
             ORDER BY soi.line_no'
        );
        $lineQuery->execute([$id]);
    } else {
        throw new InvalidArgumentException('Unsupported QuickBooks transaction type.');
    }

    $transaction['transaction_type'] = $type;
    $transaction['lines'] = $lineQuery->fetchAll();
    return $transaction;
}

function qb_export_source_revision(array $transaction): string
{
    return hash('sha256', json_encode($transaction, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function qb_export_bridge_request(array $preview): array
{
    if (($preview['valid'] ?? false) !== true || !empty($preview['errors'])) {
        throw new DomainException('A validation-only bridge request requires a fully valid local preview.');
    }
    $transaction = $preview['transaction'] ?? null;
    if (!is_array($transaction) || !in_array($transaction['transaction_type'] ?? null, qb_export_types(), true)) {
        throw new InvalidArgumentException('A typed transaction preview is required.');
    }
    $lines = [];
    foreach ($transaction['lines'] as $line) {
        if (($line['site_mapping_status'] ?? 'unresolved') !== 'verified'
            || ($line['tax_mapping_status'] ?? 'unverified') !== 'verified'
            || ($line['item_mapping_status'] ?? 'unverified') !== 'verified'
            || trim((string)($line['unit_of_measure'] ?? '')) !== ''
            || trim((string)($line['lot_number'] ?? '')) !== ''
            || trim((string)($line['expiration_date'] ?? '')) !== ''
            || trim((string)($line['item_code'] ?? '')) !== ''
            || trim((string)($line['class_name'] ?? '')) !== ''
            || trim((string)($line['line_status'] ?? '')) !== ''
            || trim((string)($line['fulfilled_quantity'] ?? '')) !== '') {
            throw new DomainException('A line contains unresolved QuickBooks mappings and cannot be sent to the bridge.');
        }
        $item = ['listId' => $line['item_list_id'] ?? null];
        $taxCode = $line['tax_list_id'] ?? null;
        $lineRequest = [
            'item' => $item,
            'description' => $line['description'],
            'quantity' => $line['quantity'],
            'rate' => $line['rate'],
            'expectedAmount' => $line['expected_amount'],
            'salesTaxCode' => $taxCode === null ? null : ['listId' => $taxCode],
        ];
        $lines[] = $lineRequest;
    }
    return [
        'protocolVersion' => '1',
        'requestId' => qb_export_uuid(),
        'operation' => 'validate',
        'validationOnly' => true,
        'idempotencyKey' => null,
        'transactionType' => $transaction['transaction_type'],
        'source' => ['id' => $transaction['source_id'], 'revision' => $preview['source_revision']],
        'transaction' => [
            'customer' => ['listId' => $transaction['customer_list_id']],
            'txnDate' => $transaction['transaction_date'],
            'refNumber' => $transaction['reference_number'],
            'poNumber' => $transaction['po_number'],
            'memo' => $transaction['memo'],
            'lines' => $lines,
            'expectedSubtotal' => $transaction['expected_subtotal'],
            'expectedTax' => null,
            'expectedTotal' => null,
        ],
    ];
}

function qb_export_validate(PDO $pdo, string $type, int $id, ?string $excludeExportId = null): array
{
    $errors = [];
    $transaction = qb_export_load($pdo, $type, $id);
    if (!$transaction) {
        return [
            'valid' => false,
            'transaction' => null,
            'source_revision' => null,
            'errors' => [['code' => 'SOURCE_NOT_FOUND', 'path' => 'source', 'message' => 'The selected source transaction does not exist.']],
        ];
    }

    $addError = static function (string $code, string $path, string $message) use (&$errors): void {
        $errors[] = ['code' => $code, 'path' => $path, 'message' => $message];
    };

    $eligibleStatuses = $type === 'invoice' ? ['Draft', 'Ready'] : ['Draft'];
    if (!in_array($transaction['status'], $eligibleStatuses, true)) {
        $addError('SOURCE_NOT_ELIGIBLE', 'status', 'This transaction is not in an export-eligible local status.');
    }
    if ($type === 'invoice') {
        $legacy = $pdo->prepare('SELECT 1 FROM qb_jobs WHERE invoice_id=? LIMIT 1');
        $legacy->execute([$id]);
        if ($legacy->fetchColumn()) {
            $addError('LEGACY_JOB_EXISTS', 'source', 'An invoice-specific QuickBooks job already exists; review its legacy status before creating a typed export.');
        }
    }
    $queued = $pdo->prepare('SELECT export_id, status FROM qb_exports WHERE transaction_type=? AND source_transaction_id=? LIMIT 1');
    $queued->execute([$type, $id]);
    $existingExport = $queued->fetch();
    if ($existingExport && $existingExport['export_id'] !== $excludeExportId) {
        $addError('EXPORT_ALREADY_EXISTS', 'source', 'A typed export record already exists for this transaction; duplicate export creation is not allowed.');
    }

    $customerMapping = qb_export_reference(
        $pdo,
        'customer',
        'client:' . (int)$transaction['customer_id'],
        $transaction['customer_list_id'] ?? null
    );
    $mappingRevision = ['customer' => $customerMapping];
    if ((int)($transaction['customer_active'] ?? 0) !== 1) {
        $addError('CUSTOMER_INACTIVE', 'customer', 'The selected customer is inactive in the local client master.');
    }
    if (!$customerMapping) {
        $addError('CUSTOMER_MAPPING_UNVERIFIED', 'customer.listId', 'A current, active QuickBooks customer ListID verification is required. Names are not used as a fallback.');
    }
    if ($type === 'sales_order'
        && trim((string)($transaction['customer_list_snapshot'] ?? '')) !== trim((string)($transaction['customer_list_id'] ?? ''))) {
        $addError('CUSTOMER_MAPPING_STALE', 'customer.listId', 'The sales-order customer mapping snapshot differs from the current customer mapping.');
    }
    if (!qb_export_valid_date((string)$transaction['transaction_date'])) {
        $addError('INVALID_TRANSACTION_DATE', 'transactionDate', 'The saved transaction date is invalid.');
    }
    if (trim((string)($transaction['reference_number'] ?? '')) === '') {
        $addError('REFERENCE_NUMBER_MISSING', 'referenceNumber', 'A local invoice or sales-order reference number is required.');
    }
    if (!$transaction['lines']) {
        $addError('LINES_MISSING', 'lines', 'At least one transaction line is required.');
    }
    $addError('TAX_TOTAL_UNRESOLVED', 'taxTotal', 'The application does not calculate QuickBooks sales tax or a reconciled transaction total.');

    $previewLines = [];
    $subtotalCents = 0;
    $subtotalOverflow = false;
    foreach ($transaction['lines'] as $index => $line) {
        $lineNo = $index + 1;
        $path = 'lines[' . $index . ']';
        $quantityRaw = (string)($line['quantity'] ?? '');
        $quantity = preg_match('/^[1-9]\d{0,8}$/D', $quantityRaw) ? (int)$quantityRaw : null;
        if ($quantity === null) {
            $addError('INVALID_QUANTITY', $path . '.quantity', 'Quantity must be a positive whole number with no more than nine digits.');
        }

        $rateCents = qb_export_money_cents($line['rate'] ?? null);
        if ($rateCents === null) {
            $addError('INVALID_RATE', $path . '.rate', 'A non-negative rate with up to two decimal places is required.');
        }
        $savedAmountCents = qb_export_money_cents($line['amount'] ?? null);
        if ($savedAmountCents === null) {
            $addError('INVALID_AMOUNT', $path . '.amount', 'The saved line amount is invalid.');
        }

        $expectedAmountCents = null;
        if ($quantity !== null && $rateCents !== null) {
            $maximumCents = 99999999999999;
            if ($rateCents > 0 && $quantity > intdiv($maximumCents, $rateCents)) {
                $addError('AMOUNT_OUT_OF_RANGE', $path . '.amount', 'Calculated line amount exceeds the supported database precision.');
            } else {
                $expectedAmountCents = $quantity * $rateCents;
                if ($savedAmountCents !== null && $expectedAmountCents !== $savedAmountCents) {
                    $addError('AMOUNT_MISMATCH', $path . '.amount', 'The saved line amount does not equal quantity multiplied by rate to two decimal places.');
                }
            }
        }
        if ($expectedAmountCents !== null && $expectedAmountCents <= 99999999999999) {
            if ($subtotalCents > 99999999999999 - $expectedAmountCents) {
                if (!$subtotalOverflow) {
                    $addError('SUBTOTAL_OUT_OF_RANGE', 'subtotal', 'The sum of line amounts exceeds the supported monetary precision.');
                }
                $subtotalOverflow = true;
            } elseif (!$subtotalOverflow) {
                $subtotalCents += $expectedAmountCents;
            }
        }

        $productId = (int)($line['product_id'] ?? 0);
        $itemListId = trim((string)($line['item_list_id'] ?? ''));
        $itemMapping = $productId > 0
            ? qb_export_reference($pdo, 'item', 'product:' . $productId, $itemListId)
            : null;
        $mappingRevision['items'][$index] = $itemMapping;
        if ($productId < 1 || (int)($line['product_active'] ?? 0) !== 1) {
            $addError('PRODUCT_INVALID', $path . '.product', 'The line must reference an active product in Product Master.');
        }
        if (!$itemMapping) {
            $addError('ITEM_MAPPING_UNVERIFIED', $path . '.item.listId', 'A current, active QuickBooks item ListID verification is required. QB Code and names are not fallbacks.');
        }
        if ($type === 'sales_order'
            && trim((string)($line['item_list_snapshot'] ?? '')) !== $itemListId) {
            $addError('ITEM_MAPPING_STALE', $path . '.item.listId', 'The sales-order item mapping snapshot differs from the current Product Master mapping.');
        }

        $description = trim((string)($line['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 255) {
            $addError('INVALID_DESCRIPTION', $path . '.description', 'A non-empty line description of 255 characters or fewer is required.');
        }

        $siteId = (int)($line['site_id'] ?? 0);
        if ($siteId < 1 || (int)($line['site_active'] ?? 0) !== 1) {
            $addError('SITE_REQUIRED', $path . '.site', 'Each line must reference an active local Site.');
        }
        $addError('SITE_DESTINATION_UNVERIFIED', $path . '.site', 'The QuickBooks destination for the local Site is unresolved; no Site value will be discarded.');

        $unit = trim((string)($line['u_m'] ?? ''));
        if ($unit !== '') {
            $addError('UNIT_DESTINATION_UNVERIFIED', $path . '.unitOfMeasure', 'The local U/M value has no verified QuickBooks unit mapping.');
        }
        if (trim((string)($line['lot_number'] ?? '')) !== '') {
            $addError('LOT_DESTINATION_UNVERIFIED', $path . '.lotNumber', 'Lot Number support and destination are not verified; the value will not be silently omitted.');
        }
        if (trim((string)($line['expiration_date'] ?? '')) !== '') {
            if (!qb_export_valid_date((string)$line['expiration_date'])) {
                $addError('INVALID_EXPIRATION_DATE', $path . '.expirationDate', 'The saved expiration date is invalid.');
            }
            $addError('EXPIRATION_DESTINATION_UNVERIFIED', $path . '.expirationDate', 'Expiration Date support and destination are not verified; the value will not be silently omitted.');
        }
        if (trim((string)($line['item_code'] ?? '')) !== '') {
            $addError('ITEM_CODE_DESTINATION_UNVERIFIED', $path . '.itemCode', 'The local item code is not a QuickBooks ListID and has no verified writable destination.');
        }
        foreach ([
            'class_name' => ['CLASS_DESTINATION_UNVERIFIED', 'class'],
            'line_status' => ['LINE_STATUS_DESTINATION_UNVERIFIED', 'lineStatus'],
            'fulfilled_quantity' => ['FULFILLED_QUANTITY_DESTINATION_UNVERIFIED', 'fulfilledQuantity'],
        ] as $field => [$code, $destination]) {
            if (trim((string)($line[$field] ?? '')) !== '') {
                $addError($code, $path . '.' . $destination, 'This local Sales Order value has no verified QuickBooks destination.');
            }
        }

        $taxCode = trim((string)($line['tax_code'] ?? ''));
        if ($taxCode !== '' && !in_array($taxCode, ['Non', 'Tax'], true)) {
            $addError('INVALID_LOCAL_TAX_CODE', $path . '.taxCode', 'The local tax value must be Non or Tax; Exempt is not an active application choice.');
        }
        $taxMapping = $taxCode !== ''
            ? qb_export_reference($pdo, 'tax_code', 'tax:' . $taxCode, null)
            : null;
        $mappingRevision['tax'][$index] = $taxMapping;
        if ($taxCode === '' || !$taxMapping) {
            $addError('TAX_MAPPING_UNVERIFIED', $path . '.taxCode', 'An explicit approved mapping from the local tax value to a QuickBooks tax code is required.');
        }

        $previewLines[] = [
            'line' => $lineNo,
            'product_name' => (string)$line['product_name'],
            'item_list_id' => $itemListId !== '' ? $itemListId : null,
            'item_code' => $line['item_code'] ?? null,
            'item_mapping_status' => $itemMapping ? 'locally recorded; current company check required' : 'unverified',
            'tax_list_id' => $taxMapping['qb_list_id'] ?? null,
            'description' => $description,
            'quantity' => $quantityRaw,
            'rate' => $rateCents === null ? (string)($line['rate'] ?? '') : qb_export_money_string($rateCents),
            'expected_amount' => $expectedAmountCents === null ? null : qb_export_money_string($expectedAmountCents),
            'saved_amount' => $savedAmountCents === null ? (string)($line['amount'] ?? '') : qb_export_money_string($savedAmountCents),
            'tax_code' => $taxCode !== '' ? $taxCode : null,
            'tax_mapping_status' => $taxMapping ? 'locally recorded; current company check required' : 'unverified',
            'site' => $line['site_name'] ?? null,
            'site_mapping_status' => 'unresolved',
            'lot_number' => $line['lot_number'] ?? null,
            'expiration_date' => $line['expiration_date'] ?? null,
            'unit_of_measure' => $unit !== '' ? $unit : null,
            'unit_mapping_status' => $unit === '' ? 'not supplied' : 'unresolved',
            'class_name' => $line['class_name'] ?? null,
            'line_status' => $line['line_status'] ?? null,
            'fulfilled_quantity' => $line['fulfilled_quantity'] ?? null,
        ];
    }

    $preview = [
        'transaction_type' => $type,
        'source_id' => (int)$transaction['id'],
        'withdrawal_no' => (string)$transaction['withdrawal_no'],
        'customer' => (string)$transaction['client_name'],
        'customer_list_id' => trim((string)($transaction['customer_list_id'] ?? '')) ?: null,
        'customer_mapping_status' => $customerMapping ? 'locally recorded; current company check required' : 'unverified',
        'reference_number' => (string)($transaction['reference_number'] ?? ''),
        'transaction_date' => (string)$transaction['transaction_date'],
        'po_number' => $transaction['po_no'],
        'memo' => $transaction['memo'],
        'status' => (string)$transaction['status'],
        'lines' => $previewLines,
        'expected_subtotal' => $subtotalOverflow ? 'exceeds supported precision' : qb_export_money_string($subtotalCents),
        'tax_total' => null,
        'expected_total' => null,
        'tax_total_status' => 'unresolved: local application does not calculate tax',
    ];

    return [
        'valid' => count($errors) === 0,
        'transaction' => $preview,
        'source_revision' => qb_export_source_revision([
            'transaction' => $transaction,
            'verified_mappings' => $mappingRevision,
        ]),
        'errors' => $errors,
    ];
}

function qb_export_create(PDO $pdo, string $type, int $id, string $expectedRevision, int $userId): array
{
    if (!in_array($type, qb_export_types(), true) || $id < 1 || !preg_match('/^[a-f0-9]{64}$/D', $expectedRevision)) {
        throw new InvalidArgumentException('Invalid export source or revision.');
    }

    $pdo->beginTransaction();
    try {
        $lockSuffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $actor = $pdo->prepare("SELECT 1 FROM employees WHERE id=? AND role='Admin' AND active=1" . $lockSuffix);
        $actor->execute([$userId]);
        if (!$actor->fetchColumn()) {
            throw new DomainException('An active administrator is required to create an export preview record.');
        }
        qb_export_lock_source($pdo, $type, $id);

        $validation = qb_export_validate($pdo, $type, $id);
        if (!$validation['valid']) {
            throw new DomainException('The transaction has blocking validation errors.');
        }
        if (!hash_equals($expectedRevision, $validation['source_revision'])) {
            throw new DomainException('The transaction changed after its preview. Refresh and review it again.');
        }
        $finalValidation = qb_export_validate($pdo, $type, $id);
        if (!$finalValidation['valid']
            || !hash_equals($validation['source_revision'], (string)$finalValidation['source_revision'])) {
            throw new DomainException('The transaction or its mappings changed during queue creation. Refresh the preview and try again.');
        }
        $existing = $pdo->prepare('SELECT export_id, status FROM qb_exports WHERE transaction_type=? AND source_transaction_id=?');
        $existing->execute([$type, $id]);
        $duplicate = $existing->fetch();
        if ($duplicate) {
            throw new DomainException('An export record already exists for this source transaction (' . $duplicate['status'] . ').');
        }

        $exportId = qb_export_uuid();
        $insert = $pdo->prepare(
            'INSERT INTO qb_exports
             (export_id, transaction_type, source_transaction_id, source_revision, snapshot_json, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $exportId,
            $type,
            $id,
            $validation['source_revision'],
            json_encode($validation['transaction'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'pending',
            $userId,
        ]);
        qb_export_insert_event($pdo, $exportId, 'created', null, 'pending', $userId, null, null);
        $audit = $pdo->prepare(
            'INSERT INTO activity_logs(employee_id, action, entity_type, entity_id, details, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $audit->execute([
            $userId,
            'QUEUE_PREVIEWED_EXPORT',
            'qb_export',
            $id,
            'Created pending preview-only ' . $type . ' export ' . $exportId,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
        $pdo->commit();
        return ['export_id' => $exportId, 'status' => 'pending'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function qb_export_lock_source(PDO $pdo, string $type, int $id): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }

    $sourceTable = $type === 'invoice' ? 'invoices' : 'sales_orders';
    $lineTable = $type === 'invoice' ? 'invoice_items' : 'sales_order_items';
    $lineForeignKey = $type === 'invoice' ? 'invoice_id' : 'sales_order_id';
    $source = $pdo->prepare("SELECT id, customer_id, withdrawal_id FROM {$sourceTable} WHERE id=? FOR UPDATE");
    $source->execute([$id]);
    $header = $source->fetch();
    if (!$header) {
        throw new RuntimeException('The source transaction no longer exists.');
    }

    $withdrawal = $pdo->prepare('SELECT id FROM withdrawals WHERE id=? FOR UPDATE');
    $withdrawal->execute([(int)$header['withdrawal_id']]);
    $withdrawal->fetchColumn();

    $customer = $pdo->prepare('SELECT id FROM clients WHERE id=? FOR UPDATE');
    $customer->execute([(int)$header['customer_id']]);
    $customer->fetchColumn();

    $lineQuery = $pdo->prepare("SELECT id, product_id, site_id, tax_code FROM {$lineTable} WHERE {$lineForeignKey}=? ORDER BY id FOR UPDATE");
    $lineQuery->execute([$id]);
    $lines = $lineQuery->fetchAll();
    $productIds = array_values(array_unique(array_filter(array_map(static fn(array $line): int => (int)($line['product_id'] ?? 0), $lines))));
    $siteIds = array_values(array_unique(array_filter(array_map(static fn(array $line): int => (int)($line['site_id'] ?? 0), $lines))));
    sort($productIds);
    sort($siteIds);
    foreach ([['products', $productIds], ['sites', $siteIds]] as [$table, $ids]) {
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $lock = $pdo->prepare("SELECT id FROM {$table} WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE");
            $lock->execute($ids);
            $lock->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    $referenceKeys = [['customer', 'client:' . (int)$header['customer_id']]];
    foreach ($productIds as $productId) {
        $referenceKeys[] = ['item', 'product:' . $productId];
    }
    foreach ($lines as $line) {
        $taxCode = trim((string)($line['tax_code'] ?? ''));
        if ($taxCode !== '') {
            $referenceKeys[] = ['tax_code', 'tax:' . $taxCode];
        }
    }
    foreach ($referenceKeys as [$referenceType, $localKey]) {
        $mapping = $pdo->prepare(
            'SELECT id, verified_by FROM qb_export_reference_verifications
             WHERE reference_type=? AND local_key=? FOR UPDATE'
        );
        $mapping->execute([$referenceType, $localKey]);
        $verification = $mapping->fetch();
        if ($verification) {
            $approver = $pdo->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE');
            $approver->execute([(int)$verification['verified_by']]);
            $approver->fetchColumn();
        }
    }
}

function qb_export_insert_event(
    PDO $pdo,
    string $exportId,
    string $eventType,
    ?string $fromStatus,
    string $toStatus,
    ?int $actorId,
    ?string $requestId,
    ?string $diagnosticCode
): void {
    if ($actorId !== null) {
        $lockSuffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $authorization = $pdo->prepare("SELECT 1 FROM employees WHERE id=? AND role='Admin' AND active=1" . $lockSuffix);
        $authorization->execute([$actorId]);
        if (!$authorization->fetchColumn()) {
            throw new DomainException('An active administrator is required for a manual export state change.');
        }
    }
    $event = $pdo->prepare(
        'INSERT INTO qb_export_events
         (export_id, event_type, from_status, to_status, actor_id, request_id, diagnostic_code)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $event->execute([$exportId, $eventType, $fromStatus, $toStatus, $actorId, $requestId, $diagnosticCode]);
}

function qb_export_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function qb_export_revalidate_record(PDO $pdo, string $exportId): array
{
    $query = $pdo->prepare(
        'SELECT transaction_type, source_transaction_id, source_revision, status
         FROM qb_exports WHERE export_id=?'
    );
    $query->execute([$exportId]);
    $record = $query->fetch();
    if (!$record) {
        return ['current' => false, 'code' => 'EXPORT_NOT_FOUND', 'message' => 'The export record does not exist.'];
    }
    if ($record['status'] !== 'pending') {
        return ['current' => false, 'code' => 'EXPORT_NOT_PENDING', 'message' => 'Only pending export records can be revalidated.'];
    }

    $validation = qb_export_validate($pdo, $record['transaction_type'], (int)$record['source_transaction_id'], $exportId);
    if (!hash_equals((string)$record['source_revision'], (string)$validation['source_revision'])) {
        return ['current' => false, 'code' => 'SOURCE_CHANGED', 'message' => 'The source transaction or mappings changed after the export snapshot was created.'];
    }
    if (!$validation['valid']) {
        return [
            'current' => false,
            'code' => 'SOURCE_NOT_VALID',
            'message' => 'Current source data or mappings have blocking validation errors.',
            'errors' => $validation['errors'],
        ];
    }
    return ['current' => true, 'transaction' => $validation['transaction']];
}

function qb_export_allowed_transition(string $from, string $to): bool
{
    $transitions = [
        'pending' => ['processing'],
        'processing' => ['succeeded', 'rejected', 'unknown'],
        'unknown' => [],
        'succeeded' => [],
        'rejected' => [],
    ];
    return in_array($to, $transitions[$from] ?? [], true);
}

function qb_export_record_attempt(
    PDO $pdo,
    string $exportId,
    int $attemptNumber,
    string $requestId,
    string $outcome,
    string $diagnosticCode,
    string $diagnosticMessage
): void {
    $allowedOutcomes = ['validation_only', 'bridge_unavailable', 'dispatched', 'succeeded', 'rejected', 'unknown'];
    if ($attemptNumber < 1
        || !preg_match('/^[a-f0-9-]{36}$/iD', $requestId)
        || !in_array($outcome, $allowedOutcomes, true)) {
        throw new InvalidArgumentException('Unsupported export attempt outcome.');
    }
    $diagnosticCode = preg_match('/^[A-Z0-9_]{1,80}$/D', $diagnosticCode) ? $diagnosticCode : 'UNCLASSIFIED';
    $message = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $diagnosticMessage) ?? '';
    if (preg_match('/<\?xml\b|<QBXML\b/i', $message)) {
        $message = 'QuickBooks XML details omitted from diagnostics.';
    }
    $message = preg_replace('/\b(authorization|signature|secret|token|password|api[-_ ]?key)\b\s*[:=]\s*\S+/i', '$1=[REDACTED]', $message) ?? '';
    $message = mb_substr(trim($message), 0, 500);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lockSuffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $export = $pdo->prepare('SELECT status, attempt_count FROM qb_exports WHERE export_id=?' . $lockSuffix);
        $export->execute([$exportId]);
        $record = $export->fetch();
        if (!$record || $attemptNumber !== ((int)$record['attempt_count'] + 1)) {
            throw new DomainException('The attempt number is stale or the export record does not exist.');
        }
        $allowedForStatus = $record['status'] === 'pending'
            ? ['validation_only', 'bridge_unavailable']
            : ($record['status'] === 'processing'
                ? ['dispatched', 'succeeded', 'rejected', 'unknown']
                : []);
        if (!in_array($outcome, $allowedForStatus, true)) {
            throw new DomainException('This attempt outcome is not permitted for the current export state.');
        }
        $insert = $pdo->prepare(
            'INSERT INTO qb_export_attempts
             (export_id, attempt_number, request_id, outcome, started_at, completed_at, diagnostic_code, diagnostic_message)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?, ?)'
        );
        $insert->execute([$exportId, $attemptNumber, $requestId, $outcome, $diagnosticCode ?: null, $message ?: null]);
        $update = $pdo->prepare('UPDATE qb_exports SET attempt_count=attempt_count+1, updated_at=CURRENT_TIMESTAMP WHERE export_id=?');
        $update->execute([$exportId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The export record for this attempt does not exist.');
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function qb_export_claim(PDO $pdo, string $exportId, string $revision, string $claimToken): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
        || !preg_match('/^[a-f0-9-]{36}$/iD', $exportId)
        || !preg_match('/^[a-f0-9]{64}$/D', $revision)
        || !preg_match('/^[a-f0-9-]{36}$/iD', $claimToken)) {
        throw new InvalidArgumentException('Invalid export claim data or database driver.');
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $claim = $pdo->prepare(
            "UPDATE qb_exports
             SET status='processing', claim_token=?, lease_until=DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 120 SECOND)
             WHERE export_id=? AND source_revision=? AND status='pending'"
        );
        $claim->execute([$claimToken, $exportId, $revision]);
        if ($claim->rowCount() !== 1) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return false;
        }
        qb_export_insert_event($pdo, $exportId, 'claimed', 'pending', 'processing', null, null, null);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function qb_export_transition(
    PDO $pdo,
    string $exportId,
    string $claimToken,
    string $from,
    string $to,
    ?string $txnId = null,
    ?string $refNumber = null,
    ?int $actorId = null,
    ?string $requestId = null,
    ?string $diagnosticCode = null
): bool {
    if (!qb_export_allowed_transition($from, $to)) {
        throw new DomainException('The requested QuickBooks export status transition is not allowed.');
    }
    $completed = in_array($to, ['succeeded', 'rejected', 'unknown'], true) ? 'CURRENT_TIMESTAMP' : 'NULL';
    $update = $pdo->prepare(
        "UPDATE qb_exports
         SET status=?, qb_txn_id=?, qb_ref_number=?, updated_at=CURRENT_TIMESTAMP,
             completed_at={$completed}, claim_token=NULL, lease_until=NULL
         WHERE export_id=? AND status=? AND claim_token=?"
    );
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $update->execute([$to, $txnId, $refNumber, $exportId, $from, $claimToken]);
        if ($update->rowCount() !== 1) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return false;
        }
        qb_export_insert_event($pdo, $exportId, 'transitioned', $from, $to, $actorId, $requestId, $diagnosticCode);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
