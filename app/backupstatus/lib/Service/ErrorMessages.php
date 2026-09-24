<?php
declare(strict_types=1);
namespace OCA\BackupStatus\Service;

/** Only stable translation keys are shown as primary error text. */
final class ErrorMessages {
    public const MESSAGES = [
        'provider_management_unconfigured' => 'Provider administration is not configured.',
        'storage_not_configured' => 'Storage host is not configured. Ask the provider administrator to configure the client-reachable SSH host and port.',
        'request_not_pending' => 'This request has expired or has already been processed. Refresh the request list.',
        'request_keys_missing' => 'This request requires two distinct write and read keys.',
        'provider_action_failed' => 'The provider action failed. Check the provider server logs.',

        'provider_invalid_response' => 'The provider returned an invalid API response. Ask the provider administrator to check Apache proxy_fcgi, the PHP-FPM handler and the provider URL, then retry.',
        'invalid_client_id' => 'Enter the existing client ID in BM-000000 format.',
        'provider_url_invalid' => 'Enter the HTTPS provider origin without a path, query or credentials.',
        'provider_invalid_request' => 'The provider rejected the recovery details. Check matching provider/client versions and the staged key pair.',

        'runtime_unavailable' => 'The local runtime helper is unavailable. Check installation, sudo permissions and server logs.',
        'consent_required' => 'Consent is required before sending an access request.',
        'provider_status_failed' => 'Provider request status could not be retrieved. Retry or check the server logs.',
        'provider_rate_limited' => 'The provider received too many requests. Wait a minute before retrying.',
        'provider_forbidden' => 'The provider refused the request. Check HTTPS and provider access settings.',
        'provider_not_found' => 'The provider endpoint or active client was not found. Check the URL and client ID.',
        'provider_unavailable' => 'The provider is unavailable. Check connectivity and the provider server logs.',

        'invalid_configuration' => 'Configuration is invalid. Review the settings and server logs.',
        'recovery_pending' => 'A recovery request is already awaiting provider approval. Review it before creating another request.',
        'recovery_request_failed' => 'Access recovery could not be requested. Check the provider connection and request status.',
        'ssh_authentication' => 'SSH authentication failed.',
        'ssh_host_verification' => 'SSH host verification failed.',
        'rsync_failed' => 'SSH file transfer failed.',
        'database_dump' => 'Database dump failed.',
        'database_restore' => 'Database restore failed.',
        's3_authentication' => 'S3 authentication or access policy denied the request.',
        's3_api' => 'S3 API request failed.',
        'storage_permission' => 'Storage permission denied.',
        'connection_failed' => 'Storage connection failed.',
        'cancelled' => 'Operation cancelled by the operator.',
        'interrupted' => 'Operation interrupted. Review the installation before retrying.',
        'service_terminated' => 'Operation stopped by service or system termination.',
        'service_timeout' => 'Operation exceeded the service time limit.',
        'cleanup_failed' => 'Operation cleanup failed. Review the installation.',
        'operation_failed' => 'The operation failed. Check the server logs.',
        'configuration_changed' => 'Settings changed after this job was queued; submit it again',
        'job_start_failed' => 'The operation could not be started. Check the server logs.',
        'host_verification' => 'SSH host verification failed.',
        'unavailable' => 'The operation failed. Check the server logs.',
    ];
    public static function message(array $record): string {
        if (empty($record['error']) && empty($record['error_code'])) { return ''; }
        $code = (string)($record['error_code'] ?? '');
        if (isset(self::MESSAGES[$code])) { return self::MESSAGES[$code]; }
        // Support persisted pre-upgrade records without exposing arbitrary exceptions.
        $error = (string)($record['error'] ?? '');
        if (in_array($error, self::MESSAGES, true)) { return $error; }
        return self::MESSAGES['operation_failed'];
    }
}
