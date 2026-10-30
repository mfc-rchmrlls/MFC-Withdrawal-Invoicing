<?php

//declare(strict_types=1);
$page_title = 'Invoice Preparation';
require __DIR__ . '/includes/layout_start.php';

$pdo = db();
$no = trim($_GET['withdrawal_no'] ?? '');

$s = $pdo->prepare(
    'SELECT w.*, c.client_name
     FROM withdrawals w
     JOIN clients c ON c.id = w.client_id
     WHERE w.withdrawal_no = ?'
);
$s->execute([$no]);
$w = $s->fetch();

if (!$w) {
    echo '<div class="alert alert-warning">Select a saved withdrawal before preparing an invoice.</div>
      <a class="btn btn-primary mb-3" href="withdrawals.php">Choose withdrawal</a>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$s = $pdo->prepare('SELECT * FROM invoices WHERE withdrawal_id = ?');
$s->execute([$w['id']]);
$inv = $s->fetch();

if (!$inv) {
    $pdo->beginTransaction();

    $s = $pdo->prepare(
        'INSERT INTO invoices
         (withdrawal_id, customer_id, invoice_date, po_no, status, created_by, updated_by)
         VALUES (?, ?, ?, ?, "Draft", ?, ?)'
    );

    $s->execute([
        $w['id'],
        $w['client_id'],
        $w['form_date'],
        $w['po_no'],
        $_SESSION['user']['id'],
        $_SESSION['user']['id']
    ]);

    $iid = (int) $pdo->lastInsertId();

    $s = $pdo->prepare(
        'SELECT wi.*, p.qb_code, p.qb_description, p.u_m,
                p.default_price
         FROM withdrawal_items wi
         LEFT JOIN products p ON p.id = wi.product_id
         WHERE wi.withdrawal_id = ?
         ORDER BY wi.line_no'
    );

    $s->execute([$w['id']]);
    $copy = $s->fetchAll();

    $ins = $pdo->prepare(
        'INSERT INTO invoice_items
         (invoice_id, withdrawal_item_id, source_type, product_id, site_id,
          product_name, quantity, item_code, description, u_m, price_each,
          amount, created_by, updated_by)
         VALUES (?, ?, "WITHDRAWAL", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($copy as $it) {
        $price = (float) ($it['default_price'] ?? 0);

        $ins->execute([
            $iid,
            $it['id'],
            $it['product_id'],
            null,
            $it['product_name'],
            $it['quantity'],
            $it['qb_code'],
            $it['qb_description'],
            $it['u_m'],
            $price,
            $price * (float) $it['quantity'],
            $_SESSION['user']['id'],
            $_SESSION['user']['id']
        ]);
    }

    $pdo->prepare(
        'UPDATE withdrawals SET status = ?, updated_by = ? WHERE id = ?'
    )->execute([
        'Invoice Preparation',
        $_SESSION['user']['id'],
        $w['id']
    ]);

    $pdo->commit();

    $s = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
    $s->execute([$iid]);
    $inv = $s->fetch();
}

$s = $pdo->prepare(
    'SELECT ii.*, si.site_name
     FROM invoice_items ii
     LEFT JOIN sites si ON si.id = ii.site_id
     WHERE ii.invoice_id = ?
     ORDER BY ii.id'
);
$s->execute([$inv['id']]);
$lines = $s->fetchAll();

$products = get_products($pdo);
$products = array_values(array_filter($products, static fn($p) => trim((string)($p['qb_code'] ?? '')) !== ''));
$customers = $pdo->query('SELECT id,client_name,qb_list_id FROM clients WHERE active=1 ORDER BY client_name')->fetchAll();
$sites = get_sites($pdo);
$locked = in_array($inv['status'], ['Invoiced', 'Queued', 'Processing', 'Uncertain Result'], true);

$sum = 0;
foreach ($lines as $ln) {
    $sum += (float) $ln['amount'];
}
?>

<div class="page-intro">
    <div>
        <h2>Prepare QuickBooks Invoice</h2>
        <p>
            Invoice draft for withdrawal <?= e($no) ?>.
            Invoice edits never overwrite the original withdrawal.
        </p>
    </div>

    <div class="toolbar">
        <a href="withdrawals.php" class="btn btn-light">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Select withdrawal
        </a>
    </div>
</div>

<!-- <div class="notice warning">
    <strong>QuickBooks posting is unavailable:</strong>
    this installation has no Web Connector service or verified company-file mappings. Drafts save locally; sending is disabled to prevent unsupported fields or duplicate inventory effects.
</div> -->

<form
    method="post"
    action="<?= e(APP_BASE_URL) ?>/backend/controllers/invoice_save.php"
    id="invoiceForm">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
    <input type="hidden" name="withdrawal_no" value="<?= e($no) ?>">

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Invoice Header</h3>
                <p>Source withdrawal and invoice details</p>
            </div>

            <span class="status-pill <?= e(status_class($inv['status'])) ?>">
                <?= e($inv['status']) ?>
            </span>
        </div>

        <div class="panel-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Source Withdrawal No.</label>
                    <input class="form-control" value="<?= e($no) ?>" readonly>
                </div>

                <div class="col-md-3">
                    <label class="form-label">CUSTOMER NAME <span class="required-mark">*</span></label>
                    <select class="form-select" name="customer_id" required <?= $locked ? 'disabled' : '' ?>>
                        <option value="">Select customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int)$customer['id'] ?>" <?= (int)$inv['customer_id'] === (int)$customer['id'] ? 'selected' : '' ?>><?= e($customer['client_name']) ?><?= !$customer['qb_list_id'] ? ' — QuickBooks mapping required' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$locked): ?><div class="form-text">Defaults to the source withdrawal customer. Changing it affects this invoice only.</div><?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">INVOICE NUMBER <span class="required-mark">*</span></label>
                    <input class="form-control" name="invoice_number" maxlength="100" value="<?= e($inv['invoice_number'] ?? '') ?>" required <?= $locked ? 'readonly' : '' ?>>
                </div>

                <div class="col-md-3">
                    <label class="form-label">
                        Invoice Date
                    </label>
                    <input
                        class="form-control"
                        type="date"
                        name="invoice_date"
                        value="<?= e($inv['invoice_date']) ?>"
                        required
                        <?= $locked ? 'readonly' : '' ?>>
                </div>

                <div class="col-md-3">
                    <label class="form-label">PO #</label>
                    <input
                        class="form-control"
                        name="po_no"
                        value="<?= e($inv['po_no'] ?? '') ?>"
                        <?= $locked ? 'readonly' : '' ?>>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="invoiceMemo">Memo</label>
                    <textarea
                        class="form-control"
                        id="invoiceMemo"
                        name="memo"
                        rows="1"
                        <?= $locked ? 'readonly' : '' ?>><?= e($inv['memo'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Invoice Lines</h3>
                <p>Site, quantity, optional lot/expiration, item details, price and tax.</p>
            </div>

            <?php if (!$locked): ?>
                <div class="toolbar">
                    <button
                        type="button"
                        class="btn btn-light"
                        id="addInvoiceLine">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        Add invoice-only item
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table invoice-lines-table" id="invoiceLines" data-pagination-exempt>
                <colgroup>
                    <col class="invoice-col-site">
                    <col class="invoice-col-quantity">
                    <col class="invoice-col-lot">
                    <col class="invoice-col-item-code">
                    <col class="invoice-col-description">
                    <col class="invoice-col-unit">
                    <col class="invoice-col-price">
                    <col class="invoice-col-amount">
                    <col class="invoice-col-expiration">
                    <col class="invoice-col-tax">
                </colgroup>
                <thead>
                    <tr>
                        <th>SITE</th>
                        <th>QUANTITY</th>
                        <th>LOT NUMBER</th>
                        <th>ITEM CODE</th>
                        <th>DESCRIPTION</th>
                        <th>U/M</th>
                        <th>PRICE EACH (PHP)</th>
                        <th>AMOUNT (PHP)</th>
                        <th>EXPIRATION DATE</th>
                        <th>TAX</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($lines as $i => $ln): ?>
                        <tr>
                            <td>
                                <select class="form-select table-input" name="items[<?= $i ?>][site_id]" required <?= $locked ? 'disabled' : '' ?>>
                                    <option value="">Select site</option><?php foreach ($sites as $site): ?><option value="<?= (int)$site['id'] ?>" <?= (string)$ln['site_id'] === (string)$site['id'] ? 'selected' : '' ?>><?= e($site['site_name']) ?></option><?php endforeach; ?>
                                </select>
                                <input
                                    type="hidden"
                                    name="items[<?= $i ?>][id]"
                                    value="<?= (int) $ln['id'] ?>">
                                <input
                                    type="hidden"
                                    name="items[<?= $i ?>][source_type]"
                                    value="<?= e($ln['source_type']) ?>">
                                <input
                                    type="hidden"
                                    name="items[<?= $i ?>][withdrawal_item_id]"
                                    value="<?= e($ln['withdrawal_item_id'] ?? '') ?>">
                            </td>

                            <td>
                                <input
                                    class="form-control table-input invoice-qty"
                                    type="number"
                                    min="1"
                                    step="1"
                                    name="items[<?= $i ?>][quantity]"
                                    value="<?= e($ln['quantity']) ?>"
                                    required
                                    <?= $locked ? 'readonly' : '' ?>>
                            </td>

                            <td>
                                <input
                                    class="form-control table-input invoice-lot"
                                    name="items[<?= $i ?>][lot_number]"
                                    value="<?= e($ln['lot_number'] ?? '') ?>"
                                    <?= $locked ? 'readonly' : '' ?>>
                            </td>

                            <td>
                                <input
                                    class="form-control table-input invoice-item-code"
                                    name="items[<?= $i ?>][item_code]"
                                    value="<?= e($ln['item_code'] ?? '') ?>"
                                    <?= $locked ? 'readonly' : '' ?>>
                            </td>

                            <td>
                                <div class="d-flex gap-1 invoice-description-picker"><select class="form-select table-input product-select" name="items[<?= $i ?>][product_id]" required aria-label="Product description" <?= $locked ? 'disabled' : '' ?>>
                                        <option value="">Select description</option><?php foreach ($products as $pr): ?><option value="<?= (int)$pr['id'] ?>" data-code="<?= e($pr['qb_code'] ?? '') ?>" data-desc="<?= e($pr['qb_description'] ?? '') ?>" data-name="<?= e($pr['product_name'] ?? '') ?>" data-um="<?= e($pr['u_m'] ?? '') ?>" data-price="<?= e($pr['default_price'] ?? '') ?>" <?= (int)$ln['product_id'] === (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['qb_description'] ?? '') ?></option><?php endforeach; ?>
                                    </select><?php if (!$locked): ?><button type="button" class="btn btn-sm btn-outline-danger remove-invoice-line" title="Remove line" aria-label="Remove invoice line">×</button><?php endif; ?></div><input type="hidden" name="items[<?= $i ?>][description]" value="<?= e($ln['description'] ?? '') ?>"><input type="hidden" name="items[<?= $i ?>][product_name]" value="<?= e($ln['product_name']) ?>">
                            </td>

                            <td>
                                <input
                                    class="form-control table-input invoice-unit"
                                    name="items[<?= $i ?>][u_m]"
                                    value="<?= e($ln['u_m'] ?? '') ?>"
                                    readonly>
                            </td>

                            <td>
                                <input
                                    class="form-control table-input invoice-price currency-input"
                                    type="text"
                                    inputmode="decimal"
                                    name="items[<?= $i ?>][price_each]"
                                    value="<?= number_format((float) $ln['price_each'], 2, '.', ',') ?>"
                                    required
                                    <?= $locked ? 'readonly' : '' ?>>
                            </td>

                            <td>
                                <input
                                    class="form-control table-input amount"
                                    value="<?= number_format((float) $ln['quantity'] * (float) $ln['price_each'], 2, '.', ',') ?>"
                                    readonly>
                            </td>

                            <td>
                                <input class="form-control table-input invoice-expiration" type="date" name="items[<?= $i ?>][expiration_date]" value="<?= e($ln['expiration_date'] ?? '') ?>" <?= $locked ? 'readonly' : '' ?>>
                            </td>
                            <td>
                                <select class="form-select table-input invoice-tax" name="items[<?= $i ?>][tax_code]" required <?= $locked ? 'disabled' : '' ?>>
                                    <?php foreach (['Non', 'Tax'] as $tax): ?>
                                        <option <?= ($ln['tax_code'] === $tax ? 'selected' : '') ?>>
                                            <?= e($tax) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="panel-body d-flex justify-content-end">
            <div class="total-card">
                <div class="total-row">
                    <span>Invoice total</span>
                    <strong>
                        &#8369;
                        <span data-invoice-total><?= number_format($sum, 2) ?></span>
                    </strong>
                </div>

                <div class="form-text mt-2">
                    Tax treatment is stored as the selected tax code;
                    actual tax calculation follows QuickBooks configuration.
                </div>
            </div>
        </div>
    </section>

    <div class="page-bottom-actions">
        <span class="muted text-small">
            Status: <?= e($inv['status']) ?>.
            No invoice has been sent to QuickBooks.
        </span>

        <div class="right">
            <a
                href="withdrawal_form.php?withdrawal_no=<?= e($no) ?>"
                class="btn btn-light">
                View source withdrawal
            </a>

            <?php if (!$locked): ?>
                <button class="btn btn-primary" type="submit">
                    Save Invoice Draft
                </button>

                <button
                    class="btn btn-teal"
                    type="button"
                    disabled
                    title="Phase 2 integration not configured">
                    Send to QuickBooks Setup Required
                </button>
            <?php endif; ?>
        </div>
    </div>
</form>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const tbody = document.querySelector('#invoiceLines tbody');
        document.querySelector('#addInvoiceLine')?.addEventListener('click', () => {
            const row = tbody.rows[0]?.cloneNode(true);
            if (!row) return;
            const index = tbody.rows.length;
            row.querySelectorAll('[name]').forEach((el) => {
                el.name = el.name.replace(/items\[\d+\]/, 'items[' + index + ']');
                if (el.name.endsWith('[source_type]')) el.value = 'ADDITIONAL';
                else if (el.name.endsWith('[id]') || el.name.endsWith('[withdrawal_item_id]')) el.value = '';
                else if (el.matches('select')) el.selectedIndex = 0;
                else if (el.classList.contains('amount')) el.value = '0.00';
                else if (el.name.endsWith('[tax_code]')) el.value = 'Non';
                else if (el.type === 'hidden' || el.name.endsWith('[lot_number]') || el.name.endsWith('[item_code]') || el.name.endsWith('[description]') || el.name.endsWith('[u_m]') || el.name.endsWith('[expiration_date]')) el.value = '';
                else if (el.name.endsWith('[quantity]')) el.value = '1';
                else if (el.name.endsWith('[price_each]')) el.value = '0.00';
            });
            row.querySelector("input[name$='[source_type]']").value = 'ADDITIONAL';
            tbody.appendChild(row);
        });
        tbody.addEventListener('click', event => {
            const button = event.target.closest('.remove-invoice-line');
            if (button && tbody.rows.length > 1) button.closest('tr').remove();
        });
        tbody.addEventListener('change', (event) => {
            if (!event.target.matches('.product-select')) return;
            const row = event.target.closest('tr');
            const opt = event.target.selectedOptions[0];
            if (!opt?.value) return;
            row.querySelector("input[name$='[item_code]']").value = opt.dataset.code || '';
            row.querySelector("input[name$='[description]']").value = opt.dataset.desc || '';
            row.querySelector("input[name$='[product_name]']").value = opt.dataset.name || '';
            row.querySelector("input[name$='[u_m]']").value = opt.dataset.um || '';
            const price = row.querySelector('.invoice-price');
            if (!price.value || window.parseMoneyValue(price.value) === 0) price.value = window.formatMoneyValue(opt.dataset.price || 0);
            updateAmount(row);
        });

        function updateAmount(row) {
            row.querySelector('.amount').value = window.formatMoneyValue(Number(row.querySelector('.invoice-qty').value || 0) * window.parseMoneyValue(row.querySelector('.invoice-price').value || 0));
            document.querySelector('[data-invoice-total]').textContent = [...tbody.querySelectorAll('.amount')].reduce((sum, input) => sum + window.parseMoneyValue(input.value), 0).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        tbody.addEventListener('input', event => {
            if (event.target.matches('.invoice-qty,.invoice-price')) updateAmount(event.target.closest('tr'));
        });
    });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>