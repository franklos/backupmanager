<?php
declare(strict_types=1);
namespace OCA\BackupStatus\Service;

final class RuntimeService {
    public function call(string $action, array $input = []): array {
        if (!in_array($action, ['settings', 'save', 'trust', 'status', 'inventory', 'enqueue', 'job', 'cancel'], true)) {
            throw new \InvalidArgumentException('Invalid runtime operation');
        }
        return $this->execute(['/usr/local/sbin/backupmanager-control', $action], $input);
    }

    public function helper(string $name, array $input = []): array {
        if (!in_array($name, ['request-info', 'recovery-request-info', 'activate-recovery-key', 'apply-provider-config'], true)) {
            throw new \InvalidArgumentException('Invalid helper');
        }
        return $this->execute(['/usr/local/sbin/backupmanager-' . $name], $input);
    }

    private function execute(array $command, array $input): array {
        $process = proc_open(array_merge(['sudo', '-n'], $command),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'Runtime unavailable'];
        }
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 45;
        do {
            $output .= stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]); // Never return stderr, which may contain sensitive system detail.
            $status = proc_get_status($process);
            if (strlen($output) > 4 * 1024 * 1024 || microtime(true) > $deadline) {
                proc_terminate($process);
                $output = '';
                break;
            }
            if ($status['running']) { usleep(20000); }
        } while ($status['running']);
        $output .= stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $result = json_decode($output, true);
        return is_array($result) ? $result : ['success' => false, 'error' => 'Runtime unavailable or timed out'];
    }
}
