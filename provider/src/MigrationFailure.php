<?php
declare(strict_types=1);
namespace BackupManager\Provider;
/** An operator-safe diagnostic, containing no driver messages or record values. */
final class MigrationFailure extends \RuntimeException {}
