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
