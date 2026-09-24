<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/backupstatus/lib/Service/ErrorMessages.php';
use OCA\BackupStatus\Service\ErrorMessages;
foreach (['en', 'nl'] as $locale) {
    $translations=json_decode(file_get_contents(dirname(__DIR__).'/app/backupstatus/l10n/'.$locale.'.json'),true)['translations'];
    foreach (ErrorMessages::MESSAGES as $code=>$text) {
        if (!isset($translations[$text])) { throw new RuntimeException('Untranslated error: '.$locale.' '.$code); }
        if (ErrorMessages::message(['error_code'=>$code,'error'=>'RAW EXCEPTION']) !== $text) { throw new RuntimeException('Error code was not preferred'); }
    }
}
if (str_contains(ErrorMessages::message(['error'=>'RAW EXCEPTION']), 'RAW')) { throw new RuntimeException('Raw error exposed'); }
if (ErrorMessages::message([]) !== '') { throw new RuntimeException('Healthy status received an error'); }
echo "Status error localization and safe fallback tests passed.\n";
