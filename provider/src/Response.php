<?php

declare(strict_types=1);

namespace BackupManager\Provider;

final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        if (($data['success'] ?? true) === false) {
            $data['error_code'] ??= match ($status) {
                400 => 'invalid_request', 401, 403 => 'provider_forbidden',
                404 => 'provider_not_found', 409 => 'request_conflict',
                429 => 'provider_rate_limited', default => 'provider_unavailable',
            };
            // Never log tokens, request bodies, raw SQL exceptions or client data.
            error_log('Backup Manager provider response: HTTP=' . $status . '; code=' . $data['error_code']);
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        exit;
    }
}
