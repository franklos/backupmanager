<?php
declare(strict_types=1);
use BackupManager\Provider\{Auth, Config, Database, RequestAdministration};
spl_autoload_register(static function (string $class): void {
    $prefix = 'BackupManager\\Provider\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require $file; }
    }
});
$config = new Config();
Auth::requireAdmin($config);
$database = new Database($config);
$administration = new RequestAdministration($database, $config);
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Invalid CSRF token.';
    } else {
        $type = (string)($_POST['request_type'] ?? '');
        $action = (string)($_POST['action'] ?? '');
        $id = (string)($_POST['request_id'] ?? '');
        if ($type === 'deletion' && in_array($action, ['approve', 'reject'], true)
            && preg_match('/^DEL-[0-9]{8}-[A-F0-9]{6}$/D', $id)) {
            $process = proc_open(['/usr/bin/php', dirname(__DIR__, 2) . '/bin/' . $action . '-deletion.php', $id, 'provider-admin'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($process)) {
                stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $result = ['success' => proc_close($process) === 0, 'error_code' => 'provider_action_failed'];
            } else { $result = ['success' => false, 'error_code' => 'provider_action_failed']; }
        } else {
            $result = $administration->act($type, $id, $action, 'provider-admin');
        }
        if ($result['success']) { $message = 'Request action completed.'; }
        else { $error = adminError($result['error_code']); }
    }
}
$inventory = $administration->inventory();
$deletions = $database->pdo()->query('SELECT deletion_request_id, client_id, delete_storage FROM deletion_requests WHERE status IN ("pending", "approved", "failed")')->fetchAll();
function t(string $value): string { return \BackupManager\Provider\AdminText::translate($value); }
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function adminError(string $code): string {
    return match ($code) {
        'storage_not_configured' => 'Storage host is not configured. Configure storage.host and storage.port in /etc/backupmanager-provider/config.php using the client-reachable SSH address and port.',
        'request_not_pending' => 'This request is expired or has already been processed. Refresh the request list.',
        'request_keys_missing' => 'This request needs two distinct write/read keys. Reject it and ask the client to request access again.',
        'provider_not_found' => 'Request not found.',
        'invalid_request' => 'Invalid request or action.',
        default => 'Provider action failed. Inspect the provider PHP error log and approval CLI diagnostics.',
    };
}
function renderRequest(array $row, bool $storage): void {
    ?><article class="request" data-request-id="<?= h($row['request_id']) ?>">
    <h3><?= h(t($row['type'] === 'recovery' ? 'Access recovery' : 'New enrollment')) ?> — <?= h($row['request_id']) ?></h3>
    <dl><?php foreach (['Status'=>'status', 'Client ID'=>'client_id', 'Source ID'=>'source_id', 'Existing source ID'=>'current_source_id', 'Source URL'=>'source_url',
        'Contact'=>'contact', 'Requested (UTC)'=>'requested_at', 'Expires (UTC)'=>'expires_at', 'Write-key fingerprint'=>'write_fingerprint', 'Read-key fingerprint'=>'read_fingerprint'] as $label=>$field): ?>
        <dt><?= h(t($label)) ?></dt><dd><?= h($field === 'status' ? t($row[$field]) : ($row[$field] ?: '—')) ?></dd>
    <?php endforeach; ?></dl>
    <?php if ($row['status'] === 'pending'): ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>">
        <input type="hidden" name="request_type" value="<?= h($row['type']) ?>">
        <input type="hidden" name="request_id" value="<?= h($row['request_id']) ?>">
        <?php if ($row['can_approve'] && $storage): ?><button name="action" value="approve"><?= h(t('Approve')) ?></button><?php endif; ?>
        <?php if ($row['can_reject']): ?><button name="action" value="reject"><?= h(t('Reject')) ?></button><?php endif; ?>
    </form>
    <?php endif; ?></article><?php
}
?><!doctype html><html lang="<?= h(\BackupManager\Provider\AdminText::language()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h(t('Backup Manager provider maintenance')) ?></title>
<style>body{font:16px system-ui;margin:0;background:#f5f6f8;color:#222}main{max-width:1100px;margin:30px auto;padding:0 16px}.request,section{background:white;border:1px solid #ddd;border-radius:8px;padding:16px;margin:16px 0;overflow-wrap:anywhere}dl{display:grid;grid-template-columns:minmax(120px,1fr) minmax(0,3fr);gap:8px}dd{margin:0}dt{font-weight:600}button{padding:8px;margin:4px}.error{color:#a00018}input{max-width:100%}@media(max-width:480px){dl{grid-template-columns:minmax(0,1fr)}}</style></head>
<body><main><nav><a href="?lang=nl">Nederlands</a> · <a href="?lang=en">English</a></nav><h1><?= h(t('Backup Manager provider maintenance')) ?></h1>
<p><?= h(t('Normal administration is available in Nextcloud → Backup Manager → Provider administration. This interface is for provider-side maintenance.')) ?></p>
<form method="post"><input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>"><button name="action" value="logout"><?= h(t('Sign out')) ?></button></form>
<p><?= h(t('Verify the requester and both key fingerprints through a trusted channel before approval. Recovery preserves the existing client and backup allocation.')) ?></p>
<?php if ($message): ?><p role="status"><?= h(t($message)) ?></p><?php endif; ?>
<?php if ($error): ?><p role="alert" class="error"><?= h(t($error)) ?></p><?php endif; ?>
<?php if (!$inventory['storage_configured']): ?><p role="alert" class="error"><?= h(t(adminError('storage_not_configured'))) ?></p><?php endif; ?>
<?php foreach (['enrollment'=>'Pending enrollments', 'recovery'=>'Pending access recovery'] as $type=>$label): ?>
<section><h2><?= h(t($label)) ?></h2>
<?php $rows=array_filter($inventory['requests'], static fn($r)=>$r['type']===$type && $r['status']==='pending'); ?>
<?php if (!$rows): ?><p><?= h(t('No requests awaiting approval.')) ?></p><?php endif; ?>
<?php foreach ($rows as $row) { renderRequest($row, $inventory['storage_configured']); } ?>
</section><?php endforeach; ?>
<details><summary><?= h(t('Request history (approved, rejected and expired)')) ?></summary>
<?php foreach ($inventory['requests'] as $row) { if ($row['status'] !== 'pending') { renderRequest($row, false); } } ?>
</details>
<details><summary><?= h(t('Deletion maintenance')) ?></summary>
<?php foreach ($deletions as $item): ?><form method="post"><p><?= h($item['client_id']) ?> — <?= h(t($item['delete_storage'] ? 'Permanently delete backup data' : 'Preserve backup data')) ?></p>
<input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>"><input type="hidden" name="request_type" value="deletion"><input type="hidden" name="request_id" value="<?= h($item['deletion_request_id']) ?>"><button name="action" value="approve"><?= h(t('Approve deletion')) ?></button><button name="action" value="reject"><?= h(t('Reject')) ?></button></form><?php endforeach; ?>
</details></main></body></html>
