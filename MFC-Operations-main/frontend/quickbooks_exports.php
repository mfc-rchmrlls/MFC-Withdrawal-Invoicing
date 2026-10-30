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
$selectedExportId = is_string($_GET['export_id'] ?? null) ? $_GET['export_id'] : '';
$preview = null;
$loadError = '';
$bridgeClient = new QuickBooksBridgeClient();
$bridge = $bridgeClient->health();
$bridgeHealth = is_array($bridge['response'] ?? null) ? $bridge['response'] : [];
$bridgeSecret = getenv('WQ_QB_BRIDGE_SECRET');
$expectedFingerprint = strtolower((string)(getenv('WQ_QB_COMPANY_FINGERPRINT') ?: ''));
$verificationSecret = getenv('WQ_QB_VERIFICATION_SECRET');
$bridgeUrl = getenv('WQ_QB_BRIDGE_URL');
$quickBooksConfiguration = [
    'bridge_url' => $bridgeUrl === false || $bridgeUrl === ''
        ? 'Using the default IPv4 loopback endpoint'
        : 'Explicitly configured; value withheld',
    'bridge_secret' => is_string($bridgeSecret) && strlen($bridgeSecret) >= 32,
    'company_fingerprint' => preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint) === 1,
    'verification_secret' => is_string($verificationSecret) && strlen($verificationSecret) >= 32,
    'php_write_enabled' => QuickBooksBridgeClient::invoiceWriteEnabled(),
];
$pdo = db();
$diagnosticSites = [];
$diagnosticProducts = [];
try {
    $diagnosticSites = $pdo->query('SELECT id, site_name FROM sites WHERE active=1 ORDER BY site_name LIMIT 100')->fetchAll();
    $diagnosticProducts = $pdo->query(
        "SELECT id, product_name, qb_list_id FROM products
         WHERE active=1 AND qb_list_id IS NOT NULL AND qb_list_id <> ''
         ORDER BY product_name LIMIT 100"
    )->fetchAll();
} catch (PDOException) {
    $loadError = 'Local Site or product diagnostic choices are unavailable; verify the deployed schema before running diagnostics.';
}
$companyDiagnostic = is_array($_SESSION['_qb_company_diagnostic'] ?? null)
    ? $_SESSION['_qb_company_diagnostic']
    : null;
unset($_SESSION['_qb_company_diagnostic']);
$lastCompanyIdentityDiagnostic = is_array($_SESSION['_qb_company_last_success'] ?? null)
    ? $_SESSION['_qb_company_last_success']
    : null;

$invoices = $pdo->query(
    'SELECT i.id, i.invoice_number AS reference_number, i.status, w.withdrawal_no, c.client_name
     FROM invoices i JOIN withdrawals w ON w.id=i.withdrawal_id
     JOIN clients c ON c.id=i.customer_id
     ORDER BY i.id DESC LIMIT 100'
)->fetchAll();
$localTaxChoices = $pdo->query(
    'SELECT tax_code, COUNT(*) AS line_count FROM invoice_items
     WHERE tax_code IS NOT NULL AND TRIM(tax_code) <> ""
     GROUP BY tax_code ORDER BY tax_code'
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
$selectedExport = null;
$postPreflight = null;
if ($selectedExportId !== '') {
    try {
        $selectedQuery = $pdo->prepare(
            'SELECT export_id,transaction_type,source_transaction_id,source_revision,snapshot_json,status,
                    qb_txn_id,qb_ref_number,attempt_count
             FROM qb_exports WHERE export_id=?'
        );
        $selectedQuery->execute([$selectedExportId]);
        $selectedExport = $selectedQuery->fetch() ?: null;
        if ($selectedExport && $selectedExport['transaction_type'] === 'invoice' && $selectedExport['status'] === 'pending') {
            $postPreflight = qb_export_post_preflight($pdo, $selectedExportId, $bridgeClient);
        }
    } catch (Throwable) {
        $loadError = 'The selected export could not be safely preflighted. No QuickBooks write was attempted.';
    }
}
$selectedSnapshot = null;
if (is_array($selectedExport)) {
    try {
        $selectedSnapshot = json_decode((string)$selectedExport['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $selectedSnapshot = null;
    }
}
$selectedAttempts = [];
if (is_array($selectedExport)) {
    try {
        $attemptQuery = $pdo->prepare(
            'SELECT outcome,diagnostic_code,diagnostic_message,started_at
             FROM qb_export_attempts WHERE export_id=? ORDER BY attempt_number DESC LIMIT 10'
        );
        $attemptQuery->execute([$selectedExportId]);
        $selectedAttempts = $attemptQuery->fetchAll();
    } catch (Throwable) {
        $selectedAttempts = [];
    }
}
$referenceLookup = is_array($_SESSION['_qb_reference_lookup'] ?? null)
    ? $_SESSION['_qb_reference_lookup']
    : null;
$referenceCandidateSummary = null;
$referenceMatchingListIds = [];
if (is_array($referenceLookup)) {
    $referenceCandidateSummary = qb_export_name_candidate_summary(
        (string)$referenceLookup['type'],
        is_array($referenceLookup['search_names'] ?? null) ? $referenceLookup['search_names'] : [],
        is_array($referenceLookup['records'] ?? null) ? $referenceLookup['records'] : []
    );
    $referenceMatchingListIds = array_column($referenceCandidateSummary['matches'], 'listId');
}
$referenceCustomers = [];
$referenceProducts = [];
$referenceLoadError = '';
try {
    $referenceCustomers = $pdo->query(
        'SELECT id, client_name FROM clients WHERE active=1 ORDER BY client_name'
    )->fetchAll();
    $referenceProducts = $pdo->query(
        'SELECT id, product_name, qb_description FROM products WHERE active=1 ORDER BY product_name'
    )->fetchAll();
} catch (Throwable) {
    $referenceLoadError = 'Local reference lists are unavailable; no QuickBooks lookup can be started.';
}
require __DIR__ . '/includes/layout_start.php';
?>
<div class="page-intro">
    <div>
        <!-- <h2>QuickBooks Export Preview</h2> -->
        <p>Admin-only invoice review and posting. QuickBooks writes remain disabled until an authorized live setup is complete.</p>
    </div>
    <span class="status-pill <?= $bridge['available'] ? 'status-success' : 'status-muted' ?>">
        <?= $bridge['available'] ? 'Local bridge responding' : 'Local bridge unavailable' ?>
    </span>
</div>
<?php if (!$bridge['available']): ?>
    <div class="alert alert-warning" role="status">
        <strong>Bridge connection not verified:</strong>
        <?= e((string)($bridge['message'] ?? 'No authenticated response was received.')) ?>
        <?php if (!empty($bridge['diagnostic_code'])): ?><code><?= e((string)$bridge['diagnostic_code']) ?></code><?php endif; ?>
        No QuickBooks company session or accounting write has been attempted by this page load.
    </div>
<?php endif; ?>
<div class="notice warning"><strong>Protected posting:</strong> the invoice posting path is implemented, but actual writes remain disabled unless PHP and the local bridge are explicitly enabled and all company-bound reference checks pass. Sales-order posting remains unavailable. No preview or diagnostic operation creates a QuickBooks transaction.</div>
<section class="panel mb-4" id="reference-verification">
    <div class="panel-header">
        <div>
            <h3>Customer and product reference verification</h3>
            <p>Only an explicit Admin request starts a company-bound exact-name lookup or saves a verified mapping. No QuickBooks records are created or changed.</p>
        </div>
    </div>
    <div class="panel-body">
        <?php if ($referenceLoadError !== ''): ?><div class="alert alert-danger" role="alert"><?= e($referenceLoadError) ?></div><?php endif; ?>
        <div class="alert alert-warning" role="status">
            Customer lookup runs separate bounded exact queries for QuickBooks <code>Name</code> and <code>FullName</code>; returned <code>CompanyName</code> is shown separately for review, but QuickBooks provides no CompanyName query filter.
            Product lookup runs bounded exact <code>Name</code>/<code>FullName</code> searches using the local product name and configured invoice description. An Admin may explicitly select a candidate after reviewing its QuickBooks identity and confirming that it is the intended product. This approval creates a direct local-product-to-QuickBooks-ListID mapping; it does not claim that the local Item Code matches any QuickBooks field. QBXML 16.0 does not expose a generic SKU field or SKU filter; <code>ManufacturerPartNumber</code> is not assumed to be the Item Code. <code>SalesDesc</code> is shown separately and is not treated as an invoice-line <code>Desc</code>.
            No unrestricted customer or item lists are enumerated. The exact active ListID and company identity are rechecked before mapping evidence is saved.
        </div>
        <div class="alert alert-info" role="note">
            Business confirmation and technical verification are separate. Customer matches must be unique and active. For products, an Admin must manually select and confirm the exact intended QuickBooks item; duplicate candidates are not auto-selected. Item Code remains local-only and is not sent by InvoiceAdd.
        </div>
        <div class="row g-3">
            <div class="col-lg-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_reference_verification.php" class="border rounded p-3">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="lookup">
                    <input type="hidden" name="reference_type" value="customer">
                    <label class="form-label" for="qb-customer-local">Local customer</label>
                    <select class="form-select mb-3" id="qb-customer-local" name="local_id" required>
                        <option value="">Select a customer</option>
                        <?php foreach ($referenceCustomers as $customer): ?>
                            <option value="<?= (int)$customer['id'] ?>"><?= e($customer['client_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" type="submit" <?= $referenceLoadError !== '' ? 'disabled' : '' ?>>Look up QuickBooks customer by name</button>
                </form>
            </div>
            <div class="col-lg-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_reference_verification.php" class="border rounded p-3">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="lookup">
                    <input type="hidden" name="reference_type" value="item">
                    <label class="form-label" for="qb-product-local">Local product</label>
                    <select class="form-select mb-3" id="qb-product-local" name="local_id" required>
                        <option value="">Select a product</option>
                        <?php foreach ($referenceProducts as $product): ?>
                            <option value="<?= (int)$product['id'] ?>"><?= e($product['product_name']) ?> — QB description: <?= e($product['qb_description'] ?? '(not configured)') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" type="submit" <?= $referenceLoadError !== '' ? 'disabled' : '' ?>>Look up QuickBooks item candidates</button>
                </form>
            </div>
        </div>
        <?php if ($referenceLookup): ?>
            <div class="mt-4" role="status">
                <h4>Lookup result: <?= e(str_replace('_', ' ', (string)$referenceLookup['status'])) ?></h4>
                <p>
                    <?= e(ucfirst((string)$referenceLookup['type'])) ?> lookup for
                    <strong><?= e((string)$referenceLookup['search_name']) ?></strong>.
                    <?php if (!empty($referenceLookup['diagnostic_code'])): ?>
                        <?= e((string)$referenceLookup['message']) ?> <code><?= e((string)$referenceLookup['diagnostic_code']) ?></code>
                    <?php elseif (in_array(($referenceLookup['status'] ?? ''), ['not_found', 'not_found_by_name'], true)): ?>
                        No exact candidates were returned.
                    <?php elseif (($referenceLookup['status'] ?? '') === 'ambiguous'): ?>
                        <?= count($referenceMatchingListIds) ?> active records match the local business fields. Verification is blocked until the duplicate QuickBooks records are resolved.
                    <?php elseif (($referenceLookup['type'] ?? '') === 'item'): ?>
                        Select only the item you have independently confirmed represents this local product. The selected active ListID and company are rechecked before saving; the local Item Code is not used as a matching field.
                    <?php endif; ?>
                </p>
                <?php foreach (($referenceLookup['records'] ?? []) as $candidate): ?>
                    <?php $candidateStatus = qb_export_name_candidate_status(
                        (string)$referenceLookup['type'],
                        is_array($referenceLookup['search_names'] ?? null) ? $referenceLookup['search_names'] : [],
                        $candidate
                    ); ?>
                    <div class="border rounded p-3 mb-2">
                        <dl class="row mb-2">
                            <dt class="col-sm-3">QuickBooks Name</dt>
                            <dd class="col-sm-9"><?= e($candidate['name']) ?></dd>
                            <dt class="col-sm-3">FullName</dt>
                            <dd class="col-sm-9"><?= e($candidate['fullName']) ?></dd>
                            <?php if ($referenceLookup['type'] === 'customer'): ?>
                                <dt class="col-sm-3">CompanyName</dt>
                                <dd class="col-sm-9"><?= e($candidate['companyName'] ?? '(not provided)') ?></dd>
                            <?php else: ?>
                                <dt class="col-sm-3">Local Item Code (source unverified)</dt>
                                <dd class="col-sm-9"><?= e($referenceLookup['local_item_code'] ?? '') ?></dd>
                                <dt class="col-sm-3">Manufacturer Part Number (not treated as Item Code)</dt>
                                <dd class="col-sm-9"><?= e($candidate['manufacturerPartNumber'] ?? '(not provided)') ?></dd>
                                <dt class="col-sm-3">SalesDesc</dt>
                                <dd class="col-sm-9"><?= e($candidate['salesDescription'] ?? '(not provided)') ?></dd>
                            <?php endif; ?>
                            <dt class="col-sm-3">Active status</dt>
                            <dd class="col-sm-9"><?= is_bool($candidate['active'] ?? null) ? ($candidate['active'] ? 'Active' : 'Inactive') : 'Unknown' ?></dd>
                            <dt class="col-sm-3">Match status</dt>
                            <dd class="col-sm-9"><?= e(str_replace('_', ' ', $candidateStatus)) ?></dd>
                        </dl>
                        <?php if (
                            $referenceLookup['type'] === 'customer'
                            && $candidateStatus === 'verified_candidate'
                            && count($referenceMatchingListIds) === 1
                            && $referenceMatchingListIds[0] === $candidate['listId']
                        ): ?>
                            <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_reference_verification.php">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="reference_type" value="<?= e($referenceLookup['type']) ?>">
                                <input type="hidden" name="local_id" value="<?= (int)$referenceLookup['local_id'] ?>">
                                <input type="hidden" name="candidate_list_id" value="<?= e($candidate['listId']) ?>">
                                <button class="btn btn-sm btn-primary" type="submit">Verify this unique active match</button>
                                <span class="form-text ms-2">Rechecks the QuickBooks reference and signed business-field evidence. No QuickBooks data is changed.</span>
                            </form>
                        <?php elseif (
                            $referenceLookup['type'] === 'item'
                            && $candidateStatus === 'item_code_source_unverified'
                        ): ?>
                            <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_reference_verification.php" class="border-top pt-3">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="reference_type" value="item">
                                <input type="hidden" name="local_id" value="<?= (int)$referenceLookup['local_id'] ?>">
                                <input type="hidden" name="candidate_list_id" value="<?= e($candidate['listId']) ?>">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="confirm-qb-item-<?= e($candidate['listId']) ?>" name="confirm_item_mapping" value="yes" required>
                                    <label class="form-check-label" for="confirm-qb-item-<?= e($candidate['listId']) ?>">
                                        I independently confirmed that this exact QuickBooks item represents the selected local product. This does not map or equate the local Item Code.
                                    </label>
                                </div>
                                <button class="btn btn-sm btn-primary" type="submit">Save Admin-selected item mapping</button>
                                <span class="form-text ms-2">Rechecks this active ListID and company before saving signed evidence. No QuickBooks data is changed.</span>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<section class="panel mb-4" id="quickbooks-diagnostic-readiness">
    <div class="panel-header">
        <div>
            <h3>Read-only mapping readiness</h3>
            <p>Diagnostics remain read-only. Invoice posting is a separate, Admin-confirmed, disabled-until-ready workflow; sales-order posting is unavailable.</p>
        </div>
    </div>
    <div class="panel-body">
        <div class="alert alert-warning" role="status">
            Live posting readiness is determined per persisted export by the current signed preflight. Bridge health does not open a QuickBooks company session, and no name match or SDK capability is treated as company-specific verification.
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr><th scope="col">Area</th><th scope="col">Status</th><th scope="col">Evidence / next action</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">Company identity</th>
                        <td>
                            <?php if (!empty($companyDiagnostic['company_identity_verified'])): ?>
                                Verified for request at <?= e((string)$companyDiagnostic['checked_at']) ?>
                            <?php elseif (!empty($lastCompanyIdentityDiagnostic['checked_at'])): ?>
                                Last verified at <?= e((string)$lastCompanyIdentityDiagnostic['checked_at']) ?>; not a current-session guarantee
                            <?php else: ?>
                                Unverified — run the authenticated check below
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $actualCompanyIdentity = $companyDiagnostic['company_identity']
                                ?? $lastCompanyIdentityDiagnostic['company_identity']
                                ?? null;
                            ?>
                            <?php if (is_array($actualCompanyIdentity)): ?>
                                Active company reported by QuickBooks: <?= e((string)($actualCompanyIdentity['companyName'] ?? '')) ?>
                                (legal name: <?= e((string)($actualCompanyIdentity['legalCompanyName'] ?? '')) ?>).
                            <?php endif; ?>
                            The signed fingerprint binds the configured file path to QuickBooks CompanyName and LegalCompanyName. An administrator must still compare these names with the intended accounting company; the fingerprint does not prove that replacement contents at the same path are correct.
                            <?php if (!getenv('WQ_QB_COMPANY_FINGERPRINT')): ?>
                                Expected company fingerprint is not configured.
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Customer</th>
                        <td>Admin verification required per local customer</td>
                        <td>Unique active exact-name matches may be approved above. The selected invoice preflight checks current signed evidence against the expected company identity.</td>
                    </tr>
                    <tr>
                        <th scope="row">Item / SKU</th>
                        <td>Explicit Admin selection required per product</td>
                        <td>Review bounded candidates and confirm the exact item. Local Item Code is not equated with a QuickBooks field and is not sent by InvoiceAdd.</td>
                    </tr>
                    <tr>
                        <th scope="row">Site</th>
                        <td>Blocked — no mapping is stored</td>
                        <td>QBXML 16.0 documents line-level <code>InventorySiteRef</code>. An Admin can request an exact <code>FullName</code> diagnostic below; even a match is not saved or treated as a verified mapping.</td>
                    </tr>
                    <tr>
                        <th scope="row">U/M</th>
                        <td>Blocked — local U/M compatibility unverified</td>
                        <td>QBXML 16.0 documents line-level <code>UnitOfMeasure</code>. A read-only query can show the stored QuickBooks item's unit set and sales default, but does not prove that the local U/M maps to it.</td>
                    </tr>
                    <tr>
                        <th scope="row">Lot Number</th>
                        <td>Blocked — company support unverified</td>
                        <td>QBXML 16.0 documents line-level <code>LotNumber</code> (maximum 40 characters). Local storage allows up to 100 characters; values must not be truncated. Company preference diagnostics can report lot tracking, but item-level behavior remains unverified.</td>
                    </tr>
                    <tr>
                        <th scope="row">Expiration Date</th>
                        <td>Blocked</td>
                        <td>No native InvoiceLineAdd expiration field is documented. Invoice custom-field definitions and the company expiration preference can be queried; definition existence does not prove line-level semantics or a usable InvoiceAdd mapping.</td>
                    </tr>
                    <tr>
                        <th scope="row">Tax</th>
                        <td>Blocked — mapping and calculation unverified</td>
                        <td>
                            Local invoice choices:
                            <?php if ($localTaxChoices): ?>
                                <?= implode(', ', array_map(
                                    static fn(array $choice): string => e((string)$choice['tax_code']) . ' (' . (int)$choice['line_count'] . ' lines)',
                                    $localTaxChoices
                                )) ?>.
                            <?php else: ?>
                                none currently stored.
                            <?php endif; ?>
                            The local <code>Tax</code>/<code>Non</code> labels are not evidence of QuickBooks tax compatibility. Read-only company tax preferences and any stored tax-code ListIDs are queryable below; exact code mapping, tax applicability, calculation, and rounding require verification against the intended company and accounting confirmation.
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Invoice reference number</th>
                        <td>Blocked — reference limit mismatch</td>
                        <td>The installed QBXML 16.0 schema documents an 11-character QuickBooks Desktop <code>RefNumber</code> limit. Preview validation blocks longer source values; they are not rewritten or truncated.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
<section class="panel mb-4" id="quickbooks-company-diagnostics">
    <div class="panel-header">
        <div>
            <h3>Authenticated read-only company diagnostics</h3>
            <p>These explicit Admin POST actions open a company-bound SDK session. They never alter QuickBooks records or mark a mapping verified.</p>
        </div>
    </div>
    <div class="panel-body">
        <div class="alert alert-secondary" role="status">
            Requests require the bridge secret and expected company fingerprint to be configured independently for PHP and the bridge. Site uses exact QuickBooks <code>FullName</code>; item U/M uses only a stored ListID, never a local SKU.
        </div>
        <h4>Connection setup status</h4>
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle">
                <thead><tr><th scope="col">Requirement</th><th scope="col">PHP runtime status</th><th scope="col">Next step</th></tr></thead>
                <tbody>
                    <tr>
                        <th scope="row">Bridge endpoint</th>
                        <td><?= e($quickBooksConfiguration['bridge_url']) ?></td>
                        <td>Bridge must listen on IPv4 loopback port 8765; startup alone does not establish company access.</td>
                    </tr>
                    <tr>
                        <th scope="row">Bridge authentication</th>
                        <td><?= $quickBooksConfiguration['bridge_secret'] ? 'Configured (minimum length met)' : 'Missing or too short' ?></td>
                        <td>Use the same protected secret in the PHP and interactive bridge process environments. Secret values are never displayed here.</td>
                    </tr>
                    <tr>
                        <th scope="row">Expected company fingerprint</th>
                        <td><?= $quickBooksConfiguration['company_fingerprint'] ? 'Configured (format valid)' : 'Missing or invalid' ?></td>
                        <td>Set only after visually confirming the intended company and obtaining its fingerprint from the read-only identity command.</td>
                    </tr>
                    <tr>
                        <th scope="row">Reference-evidence signing key</th>
                        <td><?= $quickBooksConfiguration['verification_secret'] ? 'Configured (minimum length met)' : 'Missing or too short' ?></td>
                        <td>Configure a protected PHP-side key; do not place secrets in source, web output, or chat.</td>
                    </tr>
                    <tr>
                        <th scope="row">Invoice writes in PHP</th>
                        <td><?= $quickBooksConfiguration['php_write_enabled'] ? 'Enabled by configuration' : 'Disabled' ?></td>
                        <td>Keep disabled until accounting sign-off. No live posting is authorized by this diagnostic workflow.</td>
                    </tr>
                    <tr>
                        <th scope="row">Authenticated bridge health</th>
                        <td>
                            <?php if (!empty($bridge['available'])): ?>
                                Responding; signed authentication succeeded
                            <?php else: ?>
                                Not verified<?= !empty($bridge['diagnostic_code']) ? ' (' . e((string)$bridge['diagnostic_code']) . ')' : '' ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (array_key_exists('invoiceWriteEnabled', $bridgeHealth)): ?>
                                Bridge write setting: <?= $bridgeHealth['invoiceWriteEnabled'] ? 'enabled' : 'disabled' ?>.
                            <?php else: ?>
                                Bridge write setting is unknown until authenticated health succeeds.
                            <?php endif; ?>
                            Health does not open a QuickBooks company session.
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Company identity</th>
                        <td><?= !empty($companyDiagnostic['company_identity_verified']) ? 'Verified for the explicit diagnostic shown below' : 'Not verified in this request' ?></td>
                        <td>Run “Check active QuickBooks company and session” only after the intended company file is open and confirmed.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="connection">
                    <button class="btn btn-primary" type="submit">Check active QuickBooks company and session</button>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="preferences">
                    <button class="btn btn-outline-primary" type="submit">Read company tax, inventory, and site preferences</button>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="custom_fields">
                    <button class="btn btn-outline-primary" type="submit">Read invoice custom-field definitions</button>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php" class="d-flex gap-2">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="site">
                    <label class="visually-hidden" for="qb-diagnostic-site">Local Site</label>
                    <select class="form-select" name="site_id" id="qb-diagnostic-site" required>
                        <option value="">Choose local Site</option>
                        <?php foreach ($diagnosticSites as $site): ?>
                            <option value="<?= (int)$site['id'] ?>"><?= e((string)$site['site_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" type="submit">Look up exact Site FullName</button>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php" class="d-flex gap-2">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="uom">
                    <label class="visually-hidden" for="qb-diagnostic-product">Product</label>
                    <select class="form-select" name="product_id" id="qb-diagnostic-product" required>
                        <option value="">Choose product with stored ListID</option>
                        <?php foreach ($diagnosticProducts as $product): ?>
                            <option value="<?= (int)$product['id'] ?>"><?= e((string)$product['product_name']) ?> (ListID on file)</option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" type="submit">Read item U/M set</button>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_company_diagnostics.php">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="tax">
                    <button class="btn btn-outline-primary" type="submit">Read tax preferences, default tax item, and stored tax codes</button>
                    <small class="form-text">Queries only ListIDs already present in the local tax-code verification table.</small>
                </form>
            </div>
        </div>
        <?php if ($companyDiagnostic !== null): ?>
            <div class="mt-4 alert <?= !empty($companyDiagnostic['available']) ? 'alert-info' : 'alert-warning' ?>" role="status">
                <strong><?= e((string)($companyDiagnostic['label'] ?? 'Company diagnostic')) ?>:</strong>
                <?= e((string)($companyDiagnostic['message'] ?? 'No diagnostic result is available.')) ?>
                <?php if (!empty($companyDiagnostic['diagnostic_code'])): ?>
                    <code><?= e((string)$companyDiagnostic['diagnostic_code']) ?></code>
                <?php endif; ?>
                <?php if (!empty($companyDiagnostic['request_id'])): ?>
                    <small class="d-block">Request ID: <code><?= e((string)$companyDiagnostic['request_id']) ?></code></small>
                <?php endif; ?>
                <?php if (!empty($companyDiagnostic['checked_at'])): ?>
                    <small class="d-block">Checked at: <time datetime="<?= e((string)$companyDiagnostic['checked_at']) ?>"><?= e((string)$companyDiagnostic['checked_at']) ?></time></small>
                <?php endif; ?>
            </div>
            <?php if (!empty($companyDiagnostic['available']) || !empty($companyDiagnostic['records'])): ?>
                <?php if (is_array($companyDiagnostic['localContext'] ?? null)): ?>
                    <p class="small text-muted">Local values for manual review only:</p>
                    <pre class="small border rounded p-3 bg-light"><?= e(json_encode($companyDiagnostic['localContext'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></pre>
                <?php endif; ?>
                <?php if (!empty($companyDiagnostic['records'])): ?>
                    <pre class="small border rounded p-3 bg-light mb-0"><?= e(json_encode($companyDiagnostic['records'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></pre>
                <?php else: ?>
                    <p class="small text-muted mb-0">No matching company records or definitions were returned. This remains diagnostic evidence only.</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
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
                    <dd class="col-sm-9"><?= e($transaction['tax_total_status']) ?><?= $type === 'sales_order' ? '; total unresolved' : '' ?></dd>
                </dl>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Line / Item</th>
                                <th>Mapping</th>
                                <th><?= $type === 'invoice' ? 'Local Item Code (not sent)' : 'Item Code' ?></th>
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
<?php if (is_array($selectedExport)): ?>
    <section class="panel mb-4" id="selected-export">
        <div class="panel-header">
            <div>
                <h3>Saved export review</h3>
                <p>Export <?= e($selectedExport['export_id']) ?> — <?= e($selectedExport['transaction_type']) ?> — <?= e($selectedExport['status']) ?></p>
            </div>
            <?php if ($selectedExport['status'] === 'succeeded'): ?>
                <span class="status-pill status-success">QuickBooks confirmed TxnID <?= e($selectedExport['qb_txn_id'] ?? 'missing') ?></span>
            <?php elseif ($selectedExport['status'] === 'unknown' || $selectedExport['status'] === 'processing'): ?>
                <span class="status-pill status-danger">Reconciliation required — do not retry</span>
            <?php endif; ?>
        </div>
        <div class="panel-body">
            <?php if (is_array($selectedSnapshot) && ($selectedSnapshot['transaction_type'] ?? null) === 'invoice'): ?>
                <dl class="row">
                    <dt class="col-sm-3">Invoice reference</dt>
                    <dd class="col-sm-9"><?= e($selectedSnapshot['reference_number'] ?? '') ?></dd>
                    <dt class="col-sm-3">Customer</dt>
                    <dd class="col-sm-9"><?= e($selectedSnapshot['customer'] ?? '') ?> (<?= e($selectedSnapshot['customer_list_id'] ?? '') ?>)</dd>
                    <dt class="col-sm-3">Date / subtotal</dt>
                    <dd class="col-sm-9"><?= e($selectedSnapshot['transaction_date'] ?? '') ?> / <?= e($selectedSnapshot['expected_subtotal'] ?? '') ?></dd>
                    <dt class="col-sm-3">Tax treatment</dt>
                    <dd class="col-sm-9"><?= e($selectedSnapshot['tax_total_status'] ?? 'QuickBooks tax calculation is subject to live verification.') ?></dd>
                </dl>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Line</th><th>QuickBooks item</th><th>Description</th><th>Qty</th><th>Rate</th><th>Amount</th><th>Tax code</th><th>Site / lot / U/M</th></tr></thead>
                        <tbody>
                        <?php foreach (($selectedSnapshot['lines'] ?? []) as $line): ?>
                            <tr>
                                <td><?= (int)($line['line'] ?? 0) ?></td>
                                <td><?= e($line['item_list_id'] ?? '') ?></td>
                                <td><?= e($line['description'] ?? '') ?></td>
                                <td><?= e($line['quantity'] ?? '') ?></td>
                                <td><?= e($line['rate'] ?? '') ?></td>
                                <td><?= e($line['expected_amount'] ?? '') ?></td>
                                <td><?= e($line['tax_list_id'] ?? '') ?></td>
                                <td><?= e($line['site'] ?? '') ?> / <?= e($line['lot_number'] ?? '—') ?> / <?= e($line['unit_of_measure'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-danger" role="alert">The persisted invoice snapshot is invalid or unavailable. Posting is blocked.</div>
            <?php endif; ?>

            <?php if ($selectedExport['status'] === 'pending' && $selectedExport['transaction_type'] === 'invoice'): ?>
                <?php if (!empty($postPreflight['errors'])): ?>
                    <h4>Posting preflight</h4>
                    <ul><?php foreach ($postPreflight['errors'] as $error): ?>
                        <li><code><?= e($error['code'] ?? 'PREFLIGHT') ?></code> — <?= e($error['message'] ?? 'Posting requirement is not satisfied.') ?></li>
                    <?php endforeach; ?></ul>
                <?php endif; ?>
                <form method="post" action="<?= e(APP_BASE_URL) ?>/backend/controllers/qb_export_post.php" class="border rounded p-3">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="export_id" value="<?= e($selectedExport['export_id']) ?>">
                    <label class="form-label" for="confirm-invoice-reference">To confirm, type the exact invoice reference <strong><?= e($selectedSnapshot['reference_number'] ?? '') ?></strong></label>
                    <input class="form-control mb-3" id="confirm-invoice-reference" name="confirm_reference" autocomplete="off" required>
                    <button class="btn btn-danger" type="submit" <?= !($postPreflight['ready'] ?? false) || !is_array($selectedSnapshot) ? 'disabled' : '' ?>>Send to QuickBooks</button>
                    <span class="form-text ms-2">This sends the immutable saved snapshot once. Unknown outcomes are never retried automatically.</span>
                </form>
            <?php elseif (in_array($selectedExport['status'], ['unknown', 'processing'], true)): ?>
                <div class="alert alert-warning" role="alert">The posting outcome may be ambiguous. Reconcile this invoice against the intended QuickBooks company before making any further decision; no retry action is available.</div>
            <?php endif; ?>
            <?php if ($selectedAttempts): ?>
                <h4 class="mt-3">Posting attempt history</h4>
                <ul><?php foreach ($selectedAttempts as $attempt): ?>
                    <li><?= e($attempt['started_at']) ?> — <?= e($attempt['outcome']) ?><?= $attempt['diagnostic_code'] ? ' (' . e($attempt['diagnostic_code']) . ')' : '' ?><?= $attempt['diagnostic_message'] ? ': ' . e($attempt['diagnostic_message']) : '' ?></li>
                <?php endforeach; ?></ul>
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
                    <th>Review</th>
                </tr>
            </thead>
            <tbody><?php if (!$exports): ?><tr>
                        <td colspan="8">No typed export records.</td>
                    </tr><?php endif; ?>
                <?php foreach ($exports as $export): ?><tr>
                        <td><?= e($export['export_id']) ?></td>
                        <td><?= e($export['transaction_type']) ?></td>
                        <td><?= (int)$export['source_transaction_id'] ?></td>
                        <td><?= e($export['status']) ?></td>
                        <td><?= e($export['qb_txn_id'] ?? '—') ?></td>
                        <td><?= e($export['qb_ref_number'] ?? '—') ?></td>
                        <td><?= e($export['created_at']) ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="?export_id=<?= rawurlencode($export['export_id']) ?>#selected-export">Review</a></td>
                    </tr><?php endforeach; ?></tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>