-- Non-destructive fresh-install baseline. Use bin/migrate.php for existing installations.
CREATE TABLE IF NOT EXISTS `clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(32) NOT NULL,
  `source_id` varchar(255) NOT NULL,
  `source_url` varchar(2048) NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `status` enum('active','suspended','disabled','terminated') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_at` datetime NOT NULL,
  `approved_by` varchar(255) NOT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `client_version` varchar(64) DEFAULT NULL,
  `nextcloud_version` varchar(64) DEFAULT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `api_token_hash` char(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_id` (`client_id`),
  UNIQUE KEY `uq_clients_source_id` (`source_id`),
  KEY `idx_clients_status` (`status`),
  KEY `idx_clients_last_seen_at` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `provider_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` varchar(64) NOT NULL,
  `request_token_hash` char(64) NOT NULL,
  `approval_token_hash` char(64) DEFAULT NULL,
  `approval_token_expires_at` datetime DEFAULT NULL,
  `approval_token_used_at` datetime DEFAULT NULL,
  `status` enum('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  `source_id` varchar(255) NOT NULL,
  `source_url` varchar(2048) NOT NULL,
  `requester_email` varchar(255) DEFAULT NULL,
  `public_key` text NOT NULL,
  `restore_public_key` text DEFAULT NULL,
  `ssh_fingerprint` varchar(255) NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `requester_ip` varchar(45) DEFAULT NULL,
  `client_version` varchar(64) DEFAULT NULL,
  `nextcloud_version` varchar(64) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `pending_source_id` varchar(255)
    GENERATED ALWAYS AS (
      CASE WHEN `status` = 'pending' THEN `source_id` ELSE NULL END
    ) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_id` (`request_id`),
  UNIQUE KEY `uq_provider_requests_pending_source_id` (`pending_source_id`),
  KEY `idx_provider_requests_source_id` (`source_id`),
  KEY `idx_provider_requests_status` (`status`),
  KEY `idx_provider_requests_requested_at` (`requested_at`),
  KEY `idx_provider_requests_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recovery_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recovery_request_id` varchar(64) NOT NULL,
  `request_token_hash` char(64) NOT NULL,
  `client_id` varchar(32) NOT NULL,
  `source_id` varchar(255) NOT NULL,
  `public_key` text NOT NULL,
  `restore_public_key` text DEFAULT NULL,
  `ssh_fingerprint` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `requester_ip` varchar(45) DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_request_id` (`recovery_request_id`),
  KEY `idx_recovery_requests_client_id` (`client_id`),
  KEY `idx_recovery_requests_status` (`status`),
  KEY `idx_recovery_requests_expires_at` (`expires_at`),
  CONSTRAINT `fk_recovery_requests_client`
    FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ssh_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(32) NOT NULL,
  `public_key` text NOT NULL,
  `restore_public_key` text DEFAULT NULL,
  `fingerprint` varchar(255) NOT NULL,
  `status` enum('active','revoked','replaced') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ssh_keys_fingerprint` (`fingerprint`),
  KEY `idx_ssh_keys_client_id` (`client_id`),
  KEY `idx_ssh_keys_status` (`status`),
  CONSTRAINT `fk_ssh_keys_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `storage_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(32) NOT NULL,
  `destination_type` enum('ssh','aws_s3','s3_compatible') NOT NULL,
  `storage_host` varchar(255) DEFAULT NULL,
  `storage_port` smallint(5) unsigned DEFAULT NULL,
  `storage_user` varchar(128) DEFAULT NULL,
  `storage_path` varchar(2048) DEFAULT NULL,
  `bucket` varchar(255) DEFAULT NULL,
  `prefix` varchar(1024) DEFAULT NULL,
  `region` varchar(128) DEFAULT NULL,
  `endpoint_url` varchar(2048) DEFAULT NULL,
  `quota_bytes` bigint(20) unsigned DEFAULT NULL,
  `status` enum('active','suspended','readonly','removed') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `active_client_id` varchar(64)
    GENERATED ALWAYS AS (
      CASE WHEN `status` = 'active' THEN `client_id` ELSE NULL END
    ) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_storage_allocations_active_client` (`active_client_id`),
  KEY `idx_storage_allocations_client_id` (`client_id`),
  KEY `idx_storage_allocations_status` (`status`),
  CONSTRAINT `fk_storage_allocations_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `provider_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_time` datetime NOT NULL DEFAULT current_timestamp(),
  `actor_type` enum('system','client','administrator') NOT NULL,
  `actor_id` varchar(255) DEFAULT NULL,
  `client_id` varchar(32) DEFAULT NULL,
  `request_id` varchar(64) DEFAULT NULL,
  `event_type` varchar(128) NOT NULL,
  `severity` enum('info','warning','error','security') NOT NULL DEFAULT 'info',
  `details` longtext DEFAULT NULL,
  `remote_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_provider_events_event_time` (`event_time`),
  KEY `idx_provider_events_client_id` (`client_id`),
  KEY `idx_provider_events_request_id` (`request_id`),
  KEY `idx_provider_events_event_type` (`event_type`),
  CONSTRAINT `fk_provider_events_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_provider_events_request` FOREIGN KEY (`request_id`) REFERENCES `provider_requests` (`request_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deletion_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    deletion_request_id VARCHAR(64) NOT NULL,
    client_id VARCHAR(32) NOT NULL,
    status ENUM('pending','approved','rejected','completed','failed') NOT NULL DEFAULT 'pending',
    delete_storage TINYINT(1) NOT NULL DEFAULT 1,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    requested_ip VARCHAR(45) DEFAULT NULL,
    approved_by VARCHAR(255) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deletion_request_id (deletion_request_id),
    KEY idx_deletion_client_id (client_id),
    KEY idx_deletion_status (status),
    CONSTRAINT fk_deletion_client
        FOREIGN KEY (client_id)
        REFERENCES clients(client_id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
