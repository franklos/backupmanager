<?php

declare(strict_types=1);

namespace OCA\BackupStatus\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

final class AdminSection implements IIconSection {
    public function __construct(private IL10N $l10n, private IURLGenerator $urlGenerator) {
    }

    public function getID(): string {
        return 'backupstatus';
    }

    public function getName(): string {
        return $this->l10n->t('Back-upstatus');
    }

    public function getPriority(): int {
        return 80;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('backupstatus', 'app-dark.svg');
    }
}
