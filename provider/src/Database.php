<?php

declare(strict_types=1);

namespace BackupManager\Provider;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $host = $config->get('database', 'host', 'localhost');
        $port = (int)$config->get('database', 'port', 3306);
        $name = $config->get('database', 'name');
        $charset = $config->get('database', 'charset', 'utf8mb4');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $name,
            $charset
        );

        $this->pdo = new PDO(
            $dsn,
            $config->get('database', 'user'),
            $config->get('database', 'password'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
