<?php
declare(strict_types=1);

namespace OCA\BackupStatus\Service\Removal;

use InvalidArgumentException;

final class BackupRemovalService {
    public function removeRemote(
        string $destinationType,
        RemoteRemovalInterface $remover
    ): RemovalResult {
        $allowed = [
            'ssh',
            'aws_s3',
            's3_compatible',
            'provider',
        ];

        if (!in_array($destinationType, $allowed, true)) {
            throw new InvalidArgumentException(
                'Unsupported backup destination: ' . $destinationType
            );
        }

        return $remover->remove();
    }
}
