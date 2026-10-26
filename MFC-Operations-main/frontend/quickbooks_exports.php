<?php

declare(strict_types=1);
$page_title = 'QuickBooks Export Preview';
require_once __DIR__ . '/../backend/config/app.php';
require_once __DIR__ . '/../backend/core/quickbooks_exports.php';
require_once __DIR__ . '/../backend/core/quickbooks_bridge_client.php';
require_login();
require_admin();

$type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
$sourceId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$preview = null;
$loadError = '';
$bridge = (new QuickBooksBridgeClient())->health();
$bridgeHealth = is_array($bridge['response'] ?? null) ? $bridge['response'] : [];
$pdo = db();

$invoices = $pdo->query(
    'SELECT i.id, i.invoice_number AS reference_number, i.status, w.withdrawal_no, c.client_name
     FROM invoices i JOIN withdrawals w ON w.id=i.withdrawal_id
     JOIN clients c ON c.id=i.customer_id
     ORDER BY i.id DESC LIMIT 100'
)->fetchAll();
$orders = [];
try {
    $orders = $pdo->query(
        'SELECT so.id, so.so_number AS reference_number, so.status, w.withdrawal_no, c.client_name
         FROM sales_orders so JOIN withdrawals w ON w.id=so.withdrawal_id
         JOIN clients c ON c.id=so.customer_id
         ORDER BY so.id DESC LIMIT 100'
    )->fetchAll();
} catch (PDOException) {
    $loadError = 'Sales-order tables are unavailable. Apply the sales-order preparation migration before using sales-order previews.';
}

if ($sourceId && in_array($type, qb_export_types(), true)) {
    try {
        $preview = qb_export_validate($pdo, $type, $sourceId);
    } catch (Throwable $error) {
        $loadError = 'Preview unavailable. Apply the typed-export migration and verify the database schema.';
    }
} elseif ($type !== '' || $sourceId) {
    $loadError = 'Choose a supported transaction type and a valid source record.';
}

$exports = [];
try {
    $exports = $pdo->query(
        'SELECT export_id, transaction_type, source_transaction_id, source_revision, status,
                qb_txn_id, qb_ref_number, created_at, updated_at
         FROM qb_exports ORDER BY id DESC LIMIT 100'
    )->fetchAll();
} catch (Throwable) {
    $loadError = $loadError ?: 'The typed-export migration has not been applied.';
}
require __DIR__ . '/includes/layout_start.php';
?>
<div class="page-intro">
    <div>
        <h2>QuickBooks Export Preview</h2>
        <p>Admin-only, preview-first queue. This page cannot create QuickBooks transactions.</p>
    </div>
    <span class="status-pill <?= $bridge['available'] ? 'status-success' : 'status-muted' ?>">
        <?= $bridge['available'] ? 'Local bridge responding' : 'Local bridge unavailable' ?>
    </span>
</div>
<div class="notice warning"><strong>Safe mode:</strong> only local validation and pending export-record creation are available. InvoiceAdd and SalesOrderAdd are not implemented. Site/tax/other unresolved mappings block queue creation.</div>
<?php if ($loadError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($loadError) ?></div><?php endif; ?>
<?php if (!$bridge['available']): ?><div class="alert alert-secondary" role="status"><?= e($bridge['message']) ?> (<?= e($bridge['diagnostic_code']) ?>)</div><?php endif; ?>
<?php if ($bridge['available']): ?><div class="alert alert-info" role="status">
        Bridge HTTP: responding;
        SDK registration: <?= !empty($bridgeHealth['sdkRegistered']) ? 'available' : 'unavailable' ?>;
        QuickBooks process: <?= !empty($bridgeHealth['quickBooksProcessRunning']) ? 'running' : 'not detected' ?>;
        company session: not checked by health status.
    </div><?php endif; ?>
<section class="panel mb-4">
    <div class="panel-header">
        <div>
            <h3>Select a prepared transaction</h3>
            <p>Invoices and sales orders are listed separately.</p>
        </div>
    </div>
    <div class="panel-body row g-4">
        <div class="col-lg-6">
            <h4>Invoices</h4>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Withdrawal</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody><?php if (!$invoices): ?><tr>
                                <td colspan="5">No invoice drafts.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($invoices as $item): ?><tr>
                                <td><?= e($item['reference_number'] ?: '(unnumbered)') ?></td>
                                <td><?= e($item['withdrawal_no']) ?></td>
                                <td><?= e($item['client_name']) ?></td>
                                <td><?= e($item['status']) ?></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="?type=invoice&amp;id=<?= (int)$item['id'] ?>">Preview</a></td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </div>
        <div class="col-lg-6">
            <h4>Sales Orders</h4>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>S.O. No.</th>
                            <th>Withdrawal</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody><?php if (!$orders): ?><tr>
                                <td colspan="5">No sales-order drafts. Confirm the sales-order migration is applied.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($orders as $item): ?><tr>
                                <td><?= e($item['reference_number'] ?: '(unnumbered)') ?></td>
                                <td><?= e($item['withdrawal_no']) ?></td>
                                <td><?= e($item['client_name']) ?></td>
                                <td><?= e($item['status']) ?></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="?type=sales_order&amp;id=<?= (int)$item['id'] ?>">Preview</a></td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<?php if ($preview): $transaction = $preview['transaction']; ?>
    <section class="panel mb-4">
        <div class="panel-header">
            <div>
                <h3><?= $type === 'invoice' ? 'Invoice' : 'Sales Order' ?> preview</h3>
                <p>Source #<?= (int)$sourceId ?>; revision <?= e(substr((string)$preview['source_revision'], 0, 16)) ?>…</p>
            </div>
            <span class="status-pill <?= $preview['valid'] ? 'status-success' : 'status-danger' ?>"><?= $preview['valid'] ? 'PHP validation passed' : count($preview['errors']) . ' blocking issue(s)' ?></span>
        </div>
        <div class="panel-body">
            <?php if ($transaction): ?>
                <dl class="row">
                    <dt class="col-sm-3">Customer</dt>
                    <dd class="col-sm-9"><?= e($transaction['customer']) ?> — <?= e($transaction['customer_mapping_status']) ?><?= $transaction['customer_list_id'] ? ' (' . e($transaction['customer_list_id']) . ')' : '' ?></dd>
                    <dt class="col-sm-3">Date</dt>
                    <dd class="col-sm-9"><?= e($transaction['transaction_date']) ?></dd>
                    <dt class="col-sm-3">Local reference</dt>
                    <dd class="col-sm-9"><?= e($transaction['reference_number']) ?></dd>
                    <dt class="col-sm-3">P.O. number</dt>
                    <dd class="col-sm-9"><?= e($transaction['po_number'] ?? '—') ?></dd>
                    <dt class="col-sm-3">Memo</dt>
                    <dd class="col-sm-9"><?= e($transaction['memo'] ?? '—') ?></dd>
                    <dt class="col-sm-3">Expected line subtotal</dt>
                    <dd class="col-sm-9"><?= e($transaction['expected_subtotal']) ?></dd>
                    <dt class="col-sm-3">Tax / expected total</dt>
                    <dd class="col-sm-9"><?= e($transaction['tax_total_status']) ?>; total unresolved</dd>
                </dl>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Line / Item</th>
                                <th>Mapping</th>
                                <th>Item Code</th>
                                <th>Description</th>
                                <th>Qty</th>
                                <th>Rate</th>
                                <th>Expected amount</th>
                                <th>Tax</th>
                                <th>Site / Lot / Expiry / U/M</th>
                                <th>Other local fields</th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($transaction['lines'] as $line): ?><tr>
                                    <td><?= (int)$line['line'] ?> — <?= e($line['product_name']) ?></td>
                                    <td><?= e($line['item_mapping_status']) ?><?= $line['item_list_id'] ? ' (' . e($line['item_list_id']) . ')' : '' ?></td>
                                    <td><?= e($line['item_code'] ?? '—') ?></td>
                                    <td><?= e($line['description']) ?></td>
                                    <td><?= e($line['quantity']) ?></td>
                                    <td><?= e($line['rate']) ?></td>
                                    <td><?= e($line['expected_amount'] ?? 'invalid') ?></td>
                                    <td><?= e($line['tax_code'] ?? '(missing)') ?> — <?= e($line['tax_mapping_status']) ?></td>
                                    <td>Site: <?= e($line['site'] ?? '(missing)') ?> / <?= e($line['site_mapping_status']) ?>; Lot: <?= e($line['lot_number'] ?? '—') ?>; Expiry: <?= e($line['expiration_date'] ?? '—') ?>; U/M: <?= e($line['unit_of_measure'] ?? '—') ?> (<?= e($line['unit_mapping_status']) ?>)</td>
                                    <td>Class: <?= e($line['class_name'] ?? '—') ?>; Status: <?= e($line['line_status'] ?? '—') ?>; Fulfilled: <?= e($line['fulfilled_quantity'] ?? '—') ?></td>
                                </tr><?php endforeach; ?></tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($preview['errors']): ?><h4>Blocking validation errors</h4>
                <ul class="mb-0">
                    <?php foreach ($preview['errors'] as $error): ?><li><code><?= e($error['path']) ?></code> — <?= e($error['message']) ?> <small>(<?= e($error['code']) ?>)</small></li><?php endforeach; ?>
                </ul><?php else: ?>
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_export_queue.php" class="mt-3">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="transaction_type" value="<?= e($type) ?>">
                    <input type="hidden" name="source_id" value="<?= (int)$sourceId ?>">
                    <input type="hidden" name="source_revision" value="<?= e($preview['source_revision']) ?>">
                    <button class="btn btn-outline-primary" type="submit">Save pending preview record</button>
                    <span class="form-text ms-2">This stores a pending snapshot only; it does not contact QuickBooks.</span>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<section class="panel">
    <div class="panel-header">
        <div>
            <h3>Typed export records</h3>
            <p><?= count($exports) ?> recent record(s). Unknown outcomes are never retried automatically.</p>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Export ID</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th>QB TxnID</th>
                    <th>QB RefNumber</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody><?php if (!$exports): ?><tr>
                        <td colspan="7">No typed export records.</td>
                    </tr><?php endif; ?>
                <?php foreach ($exports as $export): ?><tr>
                        <td><?= e($export['export_id']) ?></td>
                        <td><?= e($export['transaction_type']) ?></td>
                        <td><?= (int)$export['source_transaction_id'] ?></td>
                        <td><?= e($export['status']) ?></td>
                        <td><?= e($export['qb_txn_id'] ?? '—') ?></td>
                        <td><?= e($export['qb_ref_number'] ?? '—') ?></td>
                        <td><?= e($export['created_at']) ?></td>
                    </tr><?php endforeach; ?></tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>