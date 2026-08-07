<?php

declare(strict_types=1);

namespace OCA\BackupStatus\Controller;

use OCA\BackupStatus\Service\BackupStatusService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class StatusController extends Controller {
    public function __construct(IRequest $request, private BackupStatusService $statusService) {
        parent::__construct('backupstatus', $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function check(): JSONResponse {
        $status = $this->statusService->getStatus();
        return new JSONResponse(['state' => $status['state'], 'label' => $status['label']]);
    }
}
