<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/quickbooks_exports.php';
require_once __DIR__ . '/../core/quickbooks_bridge_client.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE_URL . '/frontend/quickbooks_exports.php');
    exit;
}
verify_csrf();

$exportId = is_string($_POST['export_id'] ?? null) ? $_POST['export_id'] : '';
$confirmation = is_string($_POST['confirm_reference'] ?? null) ? $_POST['confirm_reference'] : '';
try {
    if (!preg_match('/^[a-f0-9-]{36}$/iD', $exportId)) {
        throw new InvalidArgumentException('Select a valid pending invoice export.');
    }
    $snapshotQuery = db()->prepare(
        'SELECT snapshot_json,status FROM qb_exports WHERE export_id=? AND transaction_type=\'invoice\''
    );
    $snapshotQuery->execute([$exportId]);
    $export = $snapshotQuery->fetch();
    if (!$export || $export['status'] !== 'pending') {
        throw new DomainException('This invoice export is no longer pending and cannot be submitted again.');
    }
    $snapshot = json_decode((string)$export['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
    $reference = is_array($snapshot) ? (string)($snapshot['reference_number'] ?? '') : '';
    if ($reference === '' || !hash_equals($reference, $confirmation)) {
        throw new DomainException('Type the exact saved invoice reference to confirm the QuickBooks submission.');
    }

    $result = qb_export_post(db(), $exportId, (int)$_SESSION['user']['id']);
    if (($result['outcome'] ?? null) === 'succeeded') {
        flash('success', 'QuickBooks confirmed the invoice. Transaction ID: ' . $result['txn_id']);
    } elseif (($result['outcome'] ?? null) === 'unknown') {
        flash('warning', 'QuickBooks outcome is unknown. Reconciliation is required before any retry. ' . ($result['message'] ?? ''));
    } elseif (($result['outcome'] ?? null) === 'rejected') {
        flash('danger', $result['message'] ?? 'QuickBooks rejected the invoice.');
    } else {
        $errors = array_map(
            static fn(array $error): string => (string)($error['message'] ?? 'Posting preflight is incomplete.'),
            $result['errors'] ?? []
        );
        flash('danger', $errors ? implode(' ', $errors) : 'The invoice is not ready to post.');
    }
} catch (Throwable $error) {
    flash('danger', $error instanceof PDOException
        ? 'Could not safely finalize the QuickBooks posting workflow. Check the export status before taking any further action.'
        : $error->getMessage());
}

header('Location: ' . APP_BASE_URL . '/frontend/quickbooks_exports.php?export_id=' . rawurlencode($exportId));
exit;
