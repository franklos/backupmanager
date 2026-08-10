<?php

declare(strict_types=1);

namespace OCA\BackupStatus\Service;

use DateInterval;
use DateTimeImmutable;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Throwable;

final class BackupStatusService {
    public function __construct(
        private IConfig $config,
        private IClientService $clientService,
    ) {
    }

    public function getStatus(): array {
        $providerStatus = trim(
            $this->config->getAppValue(
                'backupstatus',
                'provider_request_status',
                ''
            )
        );

        $providerClientId = trim(
            $this->config->getAppValue(
                'backupstatus',
                'provider_client_id',
                ''
            )
        );

        if ($providerStatus === 'approved' && $providerClientId !== '') {
            return $this->providerStatus(
                'ok',
                'Connected',
                $providerClientId
            );
        }

        if ($providerStatus === 'pending') {
            return $this->providerStatus(
                'pending',
                'Pending approval',
                ''
            );
        }

        if ($providerStatus === 'rejected') {
            return $this->providerStatus(
                'issue',
                'Rejected',
                ''
            );
        }

        $url = trim($this->config->getAppValue('backupstatus', 'backup_url', ''));
        if ($url === '') {
            return $this->offlineStatus();
        }

        try {
            $client = $this->clientService->newClient();
            $response = $client->get($url, [
                'timeout' => 7,
                'connect_timeout' => 4,
                'allow_redirects' => true,
            ]);
            $code = $response->getStatusCode();
            if ($code < 200 || $code >= 400) {
                return $this->offlineStatus();
            }
            return $this->parseHtml((string)$response->getBody());
        } catch (Throwable) {
            return $this->offlineStatus();
        }
    }

    private function parseHtml(string $html): array {
        $data = $this->dailySegments($this->extractHistory($html, 'Laatste data-backups'));
        $database = $this->dailySegments($this->extractHistory($html, 'Laatste database-backups'));

        $issue = $this->containsIssue($html)
            || $this->segmentsContainIssue($data)
            || $this->segmentsContainIssue($database);

        return [
            'state' => $issue ? 'issue' : 'ok',
            'label' => $issue ? 'Issue' : 'OK',
            'data' => $data,
            'database' => $database,
            'checkedAt' => time(),
        ];
    }

    private function containsIssue(string $html): bool {
        $plain = strtoupper(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach (['AANDACHT NODIG', 'DATA ERROR', 'DATABASE ERROR', 'BACKUPSERVER OFFLINE'] as $needle) {
            if (str_contains($plain, $needle)) return true;
        }
        return false;
    }

    private function extractHistory(string $html, string $heading): string {
        $quoted = preg_quote($heading, '~');
        if (preg_match('~<h3[^>]*>\s*' . $quoted . '\s*</h3>\s*<pre[^>]*>(.*?)</pre>~is', $html, $match) === 1) {
            return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    /** @return array<int, array{state:string,label:string,detail:string,date:string}> */
    private function dailySegments(string $history): array {
        $byDate = [];
        $lines = preg_split('/\R/u', $history) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(\d{2}-\d{2}-\d{4})\s+(\d{2}:\d{2})\s*\|\s*([^|]+)(?:\|\s*(.*))?$/u', $line, $m) !== 1) {
                continue;
            }

            $date = $m[1];
            $time = $m[2];
            $token = strtoupper(trim($m[3]));
            $detail = $line;

            if (str_contains($token, 'START')) {
                continue;
            }

            $state = $this->tokenIsOk($token, $detail) ? 'ok' : 'issue';
            $byDate[$date] = [
                'time' => $time,
                'state' => $state,
                'label' => $state === 'ok' ? 'OK' : 'Issue',
                'detail' => $detail,
            ];
        }

        $today = new DateTimeImmutable('today');
        $segments = [];
        for ($daysAgo = 3; $daysAgo >= 0; $daysAgo--) {
            $day = $today->sub(new DateInterval('P' . $daysAgo . 'D'));
            $date = $day->format('d-m-Y');
            if (isset($byDate[$date])) {
                $segments[] = $byDate[$date] + ['date' => $date];
            } else {
                $segments[] = [
                    'state' => 'issue',
                    'label' => 'Issue',
                    'detail' => $date . ': geen afgerond resultaat',
                    'date' => $date,
                ];
            }
        }

        return $segments;
    }

    private function tokenIsOk(string $token, string $detail): bool {
        $upper = strtoupper($detail);
        return $token === 'OK'
            || str_contains($token, 'SUCCESS')
            || str_contains($token, 'GESLAAGD')
            || str_contains($upper, '| OK |')
            || str_contains($upper, 'GESLAAGD');
    }

    private function segmentsContainIssue(array $segments): bool {
        foreach ($segments as $segment) {
            if (in_array(($segment['state'] ?? ''), ['issue', 'offline'], true)) {
                return true;
            }
        }
        return false;
    }

    private function providerStatus(
        string $state,
        string $label,
        string $clientId
    ): array {
        $data = $this->localHistory(
            '/var/lib/backupmanager/status/data-history.txt'
        );

        $database = $this->localHistory(
            '/var/lib/backupmanager/status/db-history.txt'
        );

        $hasIssue =
            $this->segmentsContainIssue($data)
            || $this->segmentsContainIssue($database);

        return [
            'state' => $hasIssue ? 'issue' : $state,
            'label' => $hasIssue ? 'Issue' : $label,
            'clientId' => $clientId,
            'managedProvider' => true,
            'data' => $data,
            'database' => $database,
            'checkedAt' => time(),
        ];
    }

    private function localHistory(string $file): array {
        if (!is_readable($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (!is_array($lines)) {
            return [];
        }

        $byDate = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (
                preg_match(
                    '/^(\d{2}-\d{2}-\d{4})\s+(\d{2}:\d{2})\s*\|\s*([^|]+)(?:\|\s*(.*))?$/u',
                    $line,
                    $m
                ) !== 1
            ) {
                continue;
            }

            $date = $m[1];
            $time = $m[2];
            $token = strtoupper(trim($m[3]));

            if ($token === 'START') {
                continue;
            }

            $state = match ($token) {
                'OK' => 'ok',
                'ISSUE' => 'issue',
                default => 'issue',
            };

            $byDate[$date] = [
                'state' => $state,
                'label' => $state === 'ok' ? 'OK' : 'Issue',
                'detail' => $line,
                'date' => $date,
                'time' => $time,
            ];
        }

        $today = new DateTimeImmutable('today');
        $segments = [];

        for ($daysAgo = 3; $daysAgo >= 0; $daysAgo--) {
            $day = $today->sub(new DateInterval('P' . $daysAgo . 'D'));
            $date = $day->format('d-m-Y');

            if (isset($byDate[$date])) {
                $segments[] = $byDate[$date];
                continue;
            }

            $segments[] = [
                'state' => 'none',
                'label' => 'Geen resultaat',
                'detail' => $date . ': nog geen back-upresultaat',
                'date' => $date,
                'time' => '',
            ];
        }

        return $segments;
    }

    private function offlineStatus(): array {
        $segment = [
            'state' => 'offline',
            'label' => 'Offline',
            'detail' => 'Back-upserver niet bereikbaar',
            'date' => '',
        ];
        return [
            'state' => 'offline',
            'label' => 'Offline',
            'data' => array_fill(0, 4, $segment),
            'database' => array_fill(0, 4, $segment),
            'checkedAt' => time(),
        ];
    }
}
