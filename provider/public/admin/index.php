<?php

declare(strict_types=1);

use BackupManager\Provider\Config;
use BackupManager\Provider\Database;

session_start();

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
    $requestId = trim((string)($_POST['request_id'] ?? ''));

    if (!hash_equals((string)$_SESSION['csrf'], $csrf)) {
        $error = 'Invalid CSRF token.';
    } elseif (preg_match('/^REC-[0-9]{8}-[A-F0-9]{6}$/', $requestId) !== 1) {
        $error = 'Invalid recovery request ID.';
    } elseif (!in_array($action, ['approve', 'reject'], true)) {
        $error = 'Invalid action.';
    } else {
        $script = $action === 'approve'
            ? dirname(__DIR__, 2) . '/bin/approve-recovery.php'
            : dirname(__DIR__, 2) . '/bin/reject-recovery.php';

        $process = proc_open(
            [PHP_BINARY, $script, $requestId, 'provider-admin'],
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
                $message = $stdout !== '' ? $stdout : ucfirst($action) . ' completed.';
            } else {
                $error = $stderr !== '' ? $stderr : 'Provider action failed.';
            }
        }
    }
}

$statement = $pdo->query(
    'SELECT
        recovery_request_id,
        client_id,
        source_id,
        ssh_fingerprint,
        status,
        requested_at,
        expires_at,
        requester_ip
     FROM recovery_requests
     WHERE status = "pending"
     ORDER BY requested_at ASC'
);

$requests = $statement->fetchAll();

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
}
h1 { margin-top: 0; }
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
<p>Pending provider recovery requests</p>

<?php if ($message !== ''): ?>
    <div class="notice"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="notice error"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($requests === []): ?>

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

<?php foreach ($requests as $request): ?>
<tr>
    <td>
        <code><?= h((string)$request['recovery_request_id']) ?></code>
    </td>
    <td>
        <strong><?= h((string)$request['client_id']) ?></strong>
    </td>
    <td>
        <?= h((string)$request['source_id']) ?>
        <?php if (!empty($request['requester_ip'])): ?>
            <div class="small">
                IP: <?= h((string)$request['requester_ip']) ?>
            </div>
        <?php endif; ?>
    </td>
    <td>
        <code><?= h((string)$request['ssh_fingerprint']) ?></code>
    </td>
    <td><?= h((string)$request['requested_at']) ?></td>
    <td><?= h((string)$request['expires_at']) ?></td>
    <td>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>">
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
</main>
</body>
</html>
