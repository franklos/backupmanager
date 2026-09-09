ALTER TABLE provider_requests
    ADD COLUMN requester_email VARCHAR(255) DEFAULT NULL AFTER source_url;
