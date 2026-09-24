<?php
declare(strict_types=1);
namespace BackupManager\Provider;
// Test-only mapping of the canonical config into an isolated staged /etc.
function realpath($path) {
    if ($path === '/etc/backupmanager-provider/config.php') {
        $root = getenv('BM_JOURNEY_ROOT');
        if (!preg_match('#^/tmp/bm-integration-[A-Za-z0-9_-]+$#D', $root)) { throw new \RuntimeException('Unsafe fixture root'); }
        return $root . '/installed/etc/backupmanager-provider/config.php';
    }
    return \realpath($path);
}
function proc_open($command, $descriptors, &$pipes, ...$options) {
    if (is_array($command) && $command[0] === '/usr/bin/php') {
        $command = array_merge(['/usr/bin/php', '-d', 'auto_prepend_file=' . __FILE__], array_slice($command, 1));
    }
    return \proc_open($command, $descriptors, $pipes, ...$options);
}
