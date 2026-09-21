<?php
declare(strict_types=1);
require __DIR__ . '/provider_fixture_bootstrap.php';
$script = $argv[1] ?? '';
if (!in_array($script, ['approve.php', 'approve-recovery.php', 'approve-deletion.php', 'reject-request.php', 'reject-recovery.php', 'reject-deletion.php'], true)) {
    exit(2);
}
$argv = array_slice($argv, 1);
$argc = count($argv);
require dirname(__DIR__) . '/provider/bin/' . $script;
