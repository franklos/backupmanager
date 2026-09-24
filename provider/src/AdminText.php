<?php
declare(strict_types=1);
namespace BackupManager\Provider;
final class AdminText {
    public static function language(): string {
        $language = $_GET['lang'] ?? $_SESSION['provider_lang'] ?? (str_starts_with(strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'nl') ? 'nl' : 'en');
        return $language === 'nl' ? 'nl' : 'en';
    }
    public static function translate(string $text): string {
        static $dutch = null;
        if (self::language() !== 'nl') { return $text; }
        $dutch ??= json_decode((string)file_get_contents(__DIR__ . '/admin.nl.json'), true, 512, JSON_THROW_ON_ERROR);
        return $dutch[$text] ?? $text;
    }
}
