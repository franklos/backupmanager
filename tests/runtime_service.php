<?php
declare(strict_types=1);
namespace OCA\BackupStatus\Service {
    function proc_open($command, $descriptors, &$pipes) {
        if ($GLOBALS['runtime_fixture'] === 'throw') {
            throw new \RuntimeException('fixture process startup failed');
        }
        $output = $GLOBALS['runtime_fixture'] === 'invalid' ? 'invalid json' : '{"success":false,"error":"SSH host verification failed"}';
        return \proc_open([PHP_BINARY, '-r', 'fwrite(STDERR, "fixture host key mismatch"); echo ' . var_export($output, true) . ';'], $descriptors, $pipes);
    }
}
namespace {
    require dirname(__DIR__) . '/app/backupstatus/lib/Service/RuntimeService.php';
    $log = tempnam(sys_get_temp_dir(), 'bm-log-');
    ini_set('error_log', $log);
    try {
        $runtime = new OCA\BackupStatus\Service\RuntimeService();
        foreach (['throw', 'invalid', 'failure'] as $mode) {
            $GLOBALS['runtime_fixture'] = $mode;
            $result = $runtime->call('trust');
            $decoded = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if ($decoded['success'] !== false || empty($decoded['error'])) {
                throw new RuntimeException('Failure did not return a useful JSON error');
            }
        }
        $text = file_get_contents($log);
        foreach (['fixture process startup failed', 'invalid JSON', 'fixture host key mismatch', 'command failed'] as $expected) {
            if (!str_contains($text, $expected)) { throw new RuntimeException('Missing diagnostic: ' . $expected); }
        }
        echo "Runtime API failure regression tests passed.\n";
    } finally { unlink($log); }
}
