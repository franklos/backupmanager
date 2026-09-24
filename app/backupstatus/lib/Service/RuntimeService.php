<?php
declare(strict_types=1);
namespace OCA\BackupStatus\Service;

final class RuntimeService {
    public function call(string $action, array $input = []): array {
        if (!in_array($action, ['settings', 'save', 'trust', 'status', 'inventory', 'enqueue', 'job', 'cancel'], true)) {
            throw new \InvalidArgumentException('Invalid runtime operation');
        }
        $result = $this->execute(['/usr/local/sbin/backupmanager-control', $action], $input);
        $result['error_message'] = ErrorMessages::message($result);
        foreach (['status', 'job'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                $result[$key]['error_message'] = ErrorMessages::message($result[$key]);
            }
        }
        if (!empty($result['settings']['host_error'])) {
            $result['settings']['host_error_message'] = ErrorMessages::message([
                'error' => $result['settings']['host_error'],
                'error_code' => $result['settings']['host_error_code'] ?? 'ssh_host_verification',
            ]);
        }
        if (isset($result['settings']['connection_test'])) {
            $result['settings']['connection_test']['error_message'] = ErrorMessages::message($result['settings']['connection_test']);
        }
        return $result;
    }

    public function helper(string $name, array $input = []): array {
        if (!in_array($name, ['request-info', 'recovery-request-info', 'activate-recovery-key', 'apply-provider-config'], true)) {
            throw new \InvalidArgumentException('Invalid helper');
        }
        return $this->execute(['/usr/local/sbin/backupmanager-' . $name], $input);
    }

    private function execute(array $command, array $input): array {
        try {
            return $this->executeProcess($command, $input);
        } catch (\Throwable $error) {
            error_log('Backup Manager runtime process failure: ' . $error->getMessage());
            return ['success' => false, 'error_code' => 'runtime_unavailable', 'error' => 'Runtime unavailable; check the server log'];
        }
    }

    private function executeProcess(array $command, array $input): array {
        $process = proc_open(array_merge(['sudo', '-n'], $command),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            error_log('Backup Manager runtime process could not be started');
            return ['success' => false, 'error_code' => 'runtime_unavailable', 'error' => 'Runtime unavailable'];
        }
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $errorOutput = '';
        $deadline = microtime(true) + 45;
        do {
            $output .= stream_get_contents($pipes[1]);
            $errorOutput .= stream_get_contents($pipes[2]); // Keep stderr out of API responses; log it below for operators.
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
        $errorOutput .= stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $result = json_decode($output, true);
        if (!is_array($result)) {
            $diagnostic = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $errorOutput));
            error_log('Backup Manager runtime returned invalid JSON (exit ' . (string)$exitCode . '): ' . substr($diagnostic, 0, 1000));
            return ['success' => false, 'error_code' => 'runtime_unavailable', 'error' => 'Runtime unavailable or timed out'];
        }
        if (($result['success'] ?? true) === false && $errorOutput !== '') {
            $diagnostic = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $errorOutput));
            error_log('Backup Manager runtime command failed: ' . substr($diagnostic, 0, 1000));
        }
        return $result;
    }
}
