ALTER TABLE provider_requests
    ADD COLUMN approval_token_hash CHAR(64) DEFAULT NULL AFTER request_token_hash,
    ADD COLUMN approval_token_expires_at DATETIME DEFAULT NULL AFTER approval_token_hash,
    ADD COLUMN approval_token_used_at DATETIME DEFAULT NULL AFTER approval_token_expires_at;
