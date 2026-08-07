<?php

declare(strict_types=1);

namespace OCA\BackupStatus\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

final class Application extends App implements IBootstrap {
    public const APP_ID = 'backupstatus';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
    }

    public function boot(IBootContext $context): void {
        Util::addScript(self::APP_ID, 'menu');
        Util::addStyle(self::APP_ID, 'menu');
    }
}
