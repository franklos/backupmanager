<?php

declare(strict_types=1);

namespace OCA\BackupStatus\Controller;

use OCA\BackupStatus\Service\BackupStatusService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

final class PageController extends Controller {
    public function __construct(IRequest $request, private BackupStatusService $statusService) {
        parent::__construct('backupstatus', $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        return $this->dashboard();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function dashboard(): TemplateResponse {
        return new TemplateResponse('backupstatus', 'dashboard', $this->statusService->getStatus());
    }
}
