<?php
declare(strict_types=1);
namespace OCA\BackupStatus\Service {
    final class RuntimeService {
        public function call(string $action): array { return ['status' => $GLOBALS['fixtureStatus']]; }
    }
}
namespace {
    require dirname(__DIR__) . '/app/backupstatus/lib/Service/ErrorMessages.php';
    require dirname(__DIR__) . '/app/backupstatus/lib/Service/BackupStatusService.php';
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $GLOBALS['fixtureStatus'] = $input['status'];
    $lang = $input['lang'] ?? 'nl';
    $translations = json_decode(file_get_contents(dirname(__DIR__) . '/app/backupstatus/l10n/' . ($lang === 'nl' ? 'nl' : 'en') . '.json'), true)['translations'];
    if (!empty($input['longLabel'])) { $translations['Last successful backup'] = str_repeat('Lang label Long label ', 30); }
    $l = new class($translations, $input['locale'] ?? ($lang === 'nl' ? 'nl_NL' : 'en_US')) {
        public function __construct(private array $translations, private string $locale) {}
        public function t(string $text): string { return $this->translations[$text] ?? $text; }
        public function getLocaleCode(): string { return $this->locale; }
    };
    function p($text): void { echo htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
    function style($app, $name): void { echo '<style>' . file_get_contents(dirname(__DIR__) . '/app/backupstatus/css/' . $name . '.css') . '</style>'; }
    function script($app, $name): void { $GLOBALS['fixtureScript'] = file_get_contents(dirname(__DIR__) . '/app/backupstatus/js/' . $name . '.js'); }
    $_ = (new \OCA\BackupStatus\Service\BackupStatusService(new \OCA\BackupStatus\Service\RuntimeService()))->getStatus();
    echo '<!doctype html><html lang="' . ($lang === 'nl' ? 'nl' : 'en') . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Backup Manager fixture</title><style>';
    // Representative Nextcloud tokens and conflicting global dl geometry. No app CSS is mocked.
    echo ':root {--color-main-text:#222;--color-main-background:#fff;--color-border:#bbb;--color-warning:#8b6200;--color-background-hover:#eee;} body {margin:0;font:16px sans-serif;} dt {float:left;width:200px;} dd {margin-left:200px;} </style><body>';
    if (($input['theme'] ?? '') === 'dark') { echo '<style>:root {--color-main-text:#eee;--color-main-background:#181818;--color-background-hover:#292929;--color-border:#777;} body {background:#181818;}</style>'; }
    require dirname(__DIR__) . '/app/backupstatus/templates/dashboard.php';
    echo '<script>' . $GLOBALS['fixtureScript'] . '</script></body></html>';
}
