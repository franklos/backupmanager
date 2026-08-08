<?php
declare(strict_types=1);

namespace OCA\BackupStatus\Service\Removal;

final class RemovalResult {
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly string $message = '',
    ) {
    }

    public static function success(string $message = ''): self {
        return new self(true, 'completed', $message);
    }

    public static function pending(string $message = ''): self {
        return new self(true, 'pending_approval', $message);
    }

    public static function failure(string $message): self {
        return new self(false, 'failed', $message);
    }
}
