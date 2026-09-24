<?php
declare(strict_types=1);
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$lang=($input['lang'] ?? 'en') === 'nl' ? 'nl' : 'en';
$translations=json_decode(file_get_contents(dirname(__DIR__).'/app/backupstatus/l10n/'.$lang.'.json'),true)['translations'];
$l=new class($translations) { public function __construct(private array $translations) {} public function t($s) {return $this->translations[$s] ?? $s;} };
function p($s) {echo htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function script($a,$n) {$GLOBALS['script']=file_get_contents(dirname(__DIR__).'/app/backupstatus/js/'.$n.'.js');}
function style($a,$n) {echo '<style>'.file_get_contents(dirname(__DIR__).'/app/backupstatus/css/'.$n.'.css').'</style>';}
$_=['providerPending'=>true,'recoveryPending'=>true,'providerStatus'=>'pending','recoveryStatus'=>'pending','clientId'=>'BM-000007','providerUrl'=>'https://provider.example.test','providerEmail'=>'fixture@example.test'];
echo '<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>:root{--color-main-text:#222;--color-main-background:white;--color-border:#bbb}body{margin:0;font:16px sans-serif}input,select,button{font:inherit}</style><body>';
require dirname(__DIR__).'/app/backupstatus/templates/admin.php';
echo '<script>window.OC={requestToken:"fixture-csrf",generateUrl:p=>p,L10N:{translate:(app,text)=>('.json_encode($translations).')[text]||text}};</script><script>'.$GLOBALS['script'].'</script></body></html>';
