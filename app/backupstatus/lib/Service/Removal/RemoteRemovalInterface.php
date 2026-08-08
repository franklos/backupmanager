<?php
declare(strict_types=1);

namespace OCA\BackupStatus\Service\Removal;

interface RemoteRemovalInterface {
    public function remove(): RemovalResult;
}
