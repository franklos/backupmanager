<?php

declare(strict_types=1);

use BackupManager\Provider\Config;
use BackupManager\Provider\Database;


spl_autoload_register(function (string $class): void {
    $prefix = 'BackupManager\\Provider\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

$config = new Config(dirname(__DIR__, 2) . '/config/config.php');
\BackupManager\Provider\Auth::requireAdmin($config);
$database = new Database($config);
$pdo = $database->pdo();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    $requestType = (string)($_POST['request_type'] ?? '');
    $requestId = trim((string)($_POST['request_id'] ?? ''));

    if (!hash_equals((string)$_SESSION['csrf'], $csrf)) {
        $error = 'Invalid CSRF token.';
    } elseif (!in_array($action, ['approve', 'reject'], true)) {
        $error = 'Invalid action.';
    } else {
        $script = '';

        if (
            $requestType === 'provider'
            && preg_match('/^REQ-[0-9]{8}-[A-F0-9]{6}$/', $requestId) === 1
        ) {
            $script = $action === 'approve'
                ? dirname(__DIR__, 2) . '/bin/approve.php'
                : dirname(__DIR__, 2) . '/bin/reject-request.php';
        } elseif (
            $requestType === 'recovery'
            && preg_match('/^REC-[0-9]{8}-[A-F0-9]{6}$/', $requestId) === 1
        ) {
            $script = $action === 'approve'
                ? dirname(__DIR__, 2) . '/bin/approve-recovery.php'
                : dirname(__DIR__, 2) . '/bin/reject-recovery.php';
        } elseif ($requestType === 'deletion' && preg_match('/^DEL-[0-9]{8}-[A-F0-9]{6}$/D', $requestId) === 1) {
            $script = dirname(__DIR__, 2) . '/bin/' . ($action === 'approve' ? 'approve-deletion.php' : 'reject-deletion.php');
        } else {
            $error = 'Invalid request.';
        }

        if ($script !== '' && $error === '') {
            $process = proc_open(
                ['/usr/bin/php', $script, $requestId, 'provider-admin'],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes
            );

            if (!is_resource($process)) {
                $error = 'Unable to start provider action.';
            } else {
                $stdout = trim((string)stream_get_contents($pipes[1]));
                $stderr = trim((string)stream_get_contents($pipes[2]));

                fclose($pipes[1]);
                fclose($pipes[2]);

                $exitCode = proc_close($process);

                if ($exitCode === 0) {
                    $message = $stdout !== ''
                        ? $stdout
                        : ucfirst($action) . ' completed.';
                } else {
                    $error = $stderr !== ''
                        ? $stderr
                        : 'Provider action failed.';
                }
            }
        }
    }
}

$providerStatement = $pdo->query(
    'SELECT
        request_id,
        source_id,
        source_url,
        ssh_fingerprint,
        restore_public_key,
        requested_at,
        expires_at,
        requester_ip
     FROM provider_requests
     WHERE status = "pending"
     ORDER BY requested_at ASC'
);

$providerRequests = $providerStatement->fetchAll();

$recoveryStatement = $pdo->query(
    'SELECT
        recovery_request_id,
        client_id,
        source_id,
        ssh_fingerprint,
        restore_public_key,
        requested_at,
        expires_at,
        requester_ip
     FROM recovery_requests
     WHERE status = "pending"
     ORDER BY requested_at ASC'
);

$recoveryRequests = $recoveryStatement->fetchAll();
$deletionRequests = $pdo->query('SELECT deletion_request_id, client_id, delete_storage FROM deletion_requests WHERE status IN ("pending", "approved", "failed")')->fetchAll();

function readFingerprint(?string $key): string {
    if (!$key) { return 'Missing read key: reject and re-enroll'; }
    $parts = preg_split('/\s+/', trim($key));
    $blob = base64_decode($parts[1] ?? '', true);
    return $blob === false ? 'Invalid read key' : 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Backup Manager Provider</title>
<style>
body {
    margin: 0;
    font-family: system-ui, sans-serif;
    background: #f5f6f8;
    color: #222;
}
main {
    max-width: 1180px;
    margin: 40px auto;
    padding: 0 20px;
}
.card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 24px;
    margin-bottom: 24px;
}
h1, h2 { margin-top: 0; }
table {
    width: 100%;
    border-collapse: collapse;
}
th, td {
    text-align: left;
    padding: 10px;
    border-bottom: 1px solid #e5e5e5;
    vertical-align: top;
}
th { background: #f8f8f8; }
button {
    padding: 7px 12px;
    cursor: pointer;
}
.approve { font-weight: 600; }
.reject { margin-left: 6px; }
.notice {
    padding: 12px;
    margin-bottom: 18px;
    border: 1px solid #bbb;
    border-radius: 6px;
    white-space: pre-wrap;
}
.error { border-color: #b00020; }
.small { font-size: 0.88rem; color: #555; }
code { font-size: 0.88rem; }
</style>
</head>
<body>
<main>

<div class="card">
<h1>Backup Manager Provider</h1>
<form method="post"><input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>"><button name="action" value="logout">Sign out</button></form>
<p>Verify the requester and both key fingerprints through a trusted channel before approval. An enrollment using an existing source replaces that client's access.</p>

<?php if ($message !== ''): ?>
    <div class="notice"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="notice error"><?= h($error) ?></div>
<?php endif; ?>

<h2>Pending provider requests</h2>

<?php if ($providerRequests === []): ?>

<p><strong>No pending provider requests.</strong></p>

<?php else: ?>

<table>
<thead>
<tr>
    <th>Request</th>
    <th>Source</th>
    <th>SSH fingerprint</th>
    <th>Requested</th>
    <th>Expires</th>
    <th>Action</th>
</tr>
</thead>
<tbody>

<?php foreach ($providerRequests as $request): ?>
<tr>
    <td><code><?= h((string)$request['request_id']) ?></code></td>
    <td>
        <strong><?= h((string)$request['source_id']) ?></strong>
        <div class="small"><?= h((string)$request['source_url']) ?></div>
        <?php if (!empty($request['requester_ip'])): ?>
            <div class="small">IP: <?= h((string)$request['requester_ip']) ?></div>
        <?php endif; ?>
    </td>
    <td><div>Write: <code><?= h((string)$request['ssh_fingerprint']) ?></code></div><div>Read: <code><?= h(readFingerprint($request['restore_public_key'])) ?></code></div></td>
    <td><?= h((string)$request['requested_at']) ?></td>
    <td><?= h((string)($request['expires_at'] ?? '')) ?></td>
    <td>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>">
            <input type="hidden" name="request_type" value="provider">
            <input type="hidden" name="request_id"
                   value="<?= h((string)$request['request_id']) ?>">

            <button class="approve" type="submit" name="action" value="approve"
                    onclick="return confirm('Approve this provider request?')">
                Approve
            </button>

            <button class="reject" type="submit" name="action" value="reject"
                    onclick="return confirm('Reject this provider request?')">
                Reject
            </button>
        </form>
    </td>
</tr>
<?php endforeach; ?>

</tbody>
</table>

<?php endif; ?>
</div>

<div class="card">

<h2>Pending recovery requests</h2>

<?php if ($recoveryRequests === []): ?>

<p><strong>No pending recovery requests.</strong></p>

<?php else: ?>

<table>
<thead>
<tr>
    <th>Request</th>
    <th>Client</th>
    <th>Source</th>
    <th>SSH fingerprint</th>
    <th>Requested</th>
    <th>Expires</th>
    <th>Action</th>
</tr>
</thead>
<tbody>

<?php foreach ($recoveryRequests as $request): ?>
<tr>
    <td><code><?= h((string)$request['recovery_request_id']) ?></code></td>
    <td><strong><?= h((string)$request['client_id']) ?></strong></td>
    <td>
        <?= h((string)$request['source_id']) ?>
        <?php if (!empty($request['requester_ip'])): ?>
            <div class="small">IP: <?= h((string)$request['requester_ip']) ?></div>
        <?php endif; ?>
    </td>
    <td><div>Write: <code><?= h((string)$request['ssh_fingerprint']) ?></code></div><div>Read: <code><?= h(readFingerprint($request['restore_public_key'])) ?></code></div></td>
    <td><?= h((string)$request['requested_at']) ?></td>
    <td><?= h((string)($request['expires_at'] ?? '')) ?></td>
    <td>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>">
            <input type="hidden" name="request_type" value="recovery">
            <input type="hidden" name="request_id"
                   value="<?= h((string)$request['recovery_request_id']) ?>">

            <button class="approve" type="submit" name="action" value="approve"
                    onclick="return confirm('Approve this recovery request and replace the current SSH key?')">
                Approve
            </button>

            <button class="reject" type="submit" name="action" value="reject"
                    onclick="return confirm('Reject this recovery request?')">
                Reject
            </button>
        </form>
    </td>
</tr>
<?php endforeach; ?>

</tbody>
</table>

<?php endif; ?>

</div>
<div class="card"><h2>Pending deletion requests</h2>
<?php foreach ($deletionRequests as $item): ?>
<form method="post"><p><?= h((string)$item['client_id']) ?> — <?= $item['delete_storage'] ? 'Permanently delete backup data' : 'Preserve backup data' ?></p>
<input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>">
<input type="hidden" name="request_type" value="deletion">
<input type="hidden" name="request_id" value="<?= h((string)$item['deletion_request_id']) ?>">
<button name="action" value="approve">Approve deletion</button><button name="action" value="reject">Reject</button></form>
<?php endforeach; ?></div>
</main>
</body>
</html>
