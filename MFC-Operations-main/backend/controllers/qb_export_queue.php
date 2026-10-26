<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/quickbooks_exports.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE_URL . '/frontend/quickbooks_exports.php');
    exit;
}
verify_csrf();

$type = is_string($_POST['transaction_type'] ?? null) ? $_POST['transaction_type'] : '';
$sourceId = filter_var($_POST['source_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$revision = is_string($_POST['source_revision'] ?? null) ? $_POST['source_revision'] : '';
try {
    if (!$sourceId) {
        throw new InvalidArgumentException('Select a valid source transaction.');
    }
    $result = qb_export_create(db(), $type, $sourceId, $revision, (int)$_SESSION['user']['id']);
    flash('success', 'Preview passed. An export record was saved as pending; no request was sent to QuickBooks.');
} catch (Throwable $error) {
    $message = $error instanceof PDOException
        ? 'Could not create the export record. Refresh the preview and check whether an export already exists.'
        : $error->getMessage();
    flash('danger', $message);
}

$query = http_build_query(['type' => $type, 'id' => (int)$sourceId]);
header('Location: ' . APP_BASE_URL . '/frontend/quickbooks_exports.php?' . $query);
exit;
