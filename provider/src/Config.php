<?php

declare(strict_types=1);

namespace BackupManager\Provider;

use RuntimeException;

final class Config
{
    public const FILE = '/etc/backupmanager-provider/config.php';
    private array $config;

    public function __construct(string $file = self::FILE)
    {
        $file = realpath($file) ?: $file;
        if (!is_file($file)) {
            throw new RuntimeException('Provider configuration file not found');
        }

        $config = require $file;

        if (!is_array($config)) {
            throw new RuntimeException('Invalid provider configuration');
        }

        if (($config['storage']['root'] ?? '/var/lib/backupmanager-provider') !== '/var/lib/backupmanager-provider'
            || ($config['storage']['user'] ?? 'backupstore') !== 'backupstore') {
            throw new RuntimeException('Provider helpers require the documented fixed storage root and backupstore account');
        }
        $this->config = $config;
    }

    public function get(string $section, string $key, mixed $default = null): mixed
    {
        return $this->config[$section][$key] ?? $default;
    }
}
