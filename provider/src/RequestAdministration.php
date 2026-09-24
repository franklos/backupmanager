<?php
declare(strict_types=1);
namespace BackupManager\Provider;

/** Shared, read-only inventory and explicit actions for API and maintenance UI. */
final class RequestAdministration {
    public function __construct(private Database $database, private Config $config) {}

    public static function fingerprint(?string $key): string {
        if (!$key) { return ''; }
        $parts = preg_split('/\s+/', trim($key));
        $blob = base64_decode($parts[1] ?? '', true);
        return $blob === false || $blob === '' ? '' : 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    public function inventory(): array {
        $pdo = $this->database->pdo();
        $requests = [];
        foreach (['enrollment' => ['provider_requests', 'request_id'], 'recovery' => ['recovery_requests', 'recovery_request_id']] as $type => [$table, $field]) {
            $binding = $type === 'recovery' ? 'c.client_id=r.client_id' : 'c.source_id=r.source_id';
            $statement = $pdo->query('SELECT r.*, c.client_id AS bound_client_id, c.source_id AS current_source_id,
                c.source_url AS client_source_url, c.contact_email AS client_contact,
                CASE WHEN r.status="pending" AND (r.expires_at IS NULL OR r.expires_at <= UTC_TIMESTAMP()) THEN "expired" ELSE r.status END AS effective_status
                FROM ' . $table . ' r LEFT JOIN clients c ON ' . $binding . ' ORDER BY r.requested_at DESC, r.id DESC LIMIT 500');
            foreach ($statement->fetchAll() as $row) {
                $status = $row['effective_status'];
                $write = self::fingerprint($row['public_key']);
                $read = self::fingerprint($row['restore_public_key'] ?? null);
                $requests[] = [
                    'request_id' => $row[$field], 'type' => $type,
                    'client_id' => $row['client_id'] ?? $row['bound_client_id'] ?? '', 'source_id' => $row['source_id'],
                    'current_source_id' => $row['current_source_id'] ?? '',
                    'source_url' => $row['source_url'] ?? $row['client_source_url'] ?? '', 'contact' => $row['requester_email'] ?? $row['client_contact'] ?? '',
                    'requested_at' => $row['requested_at'], 'expires_at' => $row['expires_at'] ?? '',
                    'status' => $status, 'write_fingerprint' => $write, 'read_fingerprint' => $read,
                    'can_approve' => $status === 'pending' && $write !== '' && $read !== '' && $read !== $write,
                    'can_reject' => $status === 'pending',
                ];
            }
        }
        try { StorageEndpoint::configured($this->config); $storage = true; }
        catch (\RuntimeException) { $storage = false; }
        return ['success' => true, 'requests' => $requests, 'storage_configured' => $storage,
            'error_code' => $storage ? '' : 'storage_not_configured'];
    }

    public function act(string $type, string $id, string $action, string $actor): array {
        $prefix = ['enrollment' => 'REQ', 'recovery' => 'REC'][$type] ?? '';
        if ($prefix === '' || !preg_match('/^' . $prefix . '-[0-9]{8}-[A-F0-9]{6}$/D', $id)
            || !in_array($action, ['approve', 'reject'], true) || $actor === '' || strlen($actor) > 200 || preg_match('/[\x00-\x1f]/', $actor)) {
            return ['success' => false, 'error_code' => 'invalid_request'];
        }
        // The CLI repeats these checks under its row/approval lock to prevent races.
        $inventory = $this->inventory();
        $item = null;
        foreach ($inventory['requests'] as $row) {
            if ($row['request_id'] === $id && $row['type'] === $type) { $item = $row; break; }
        }
        if ($item === null) { return ['success' => false, 'error_code' => 'provider_not_found']; }
        if ($item['status'] !== 'pending') { return ['success' => false, 'error_code' => 'request_not_pending']; }
        if ($action === 'approve' && !$inventory['storage_configured']) { return ['success' => false, 'error_code' => 'storage_not_configured']; }
        if ($action === 'approve' && !$item['can_approve']) { return ['success' => false, 'error_code' => 'request_keys_missing']; }
        $script = $type === 'recovery' ? $action . '-recovery.php' : ($action === 'approve' ? 'approve.php' : 'reject-request.php');
        $process = proc_open(['/usr/bin/php', dirname(__DIR__) . '/bin/' . $script, $id, $actor],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { return ['success' => false, 'error_code' => 'provider_action_failed']; }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $detail = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($process);
        $sqlState = preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/', $detail, $sqlMatch) ? $sqlMatch[1] : 'none';
        error_log('Backup Manager administration action: sqlstate=' . $sqlState . '; type=' . $type . '; id=' . $id . '; action=' . $action . '; exit=' . $code);
        if ($code !== 0) {
            $error = str_contains($detail, 'storage_not_configured:') ? 'storage_not_configured'
                : ((str_contains($detail, 'expired') || str_contains($detail, 'not pending')) ? 'request_not_pending' : 'provider_action_failed');
            return ['success' => false, 'error_code' => $error];
        }
        return ['success' => true, 'request_id' => $id, 'type' => $type, 'status' => $action === 'approve' ? 'approved' : 'rejected'];
    }
}
