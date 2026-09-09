ALTER TABLE provider_requests
    ADD COLUMN pending_source_id VARCHAR(255)
        GENERATED ALWAYS AS (
            CASE WHEN status = 'pending' THEN source_id ELSE NULL END
        ) STORED,
    ADD UNIQUE KEY uq_provider_requests_pending_source_id (pending_source_id);

ALTER TABLE storage_allocations
    ADD COLUMN active_client_id VARCHAR(64)
        GENERATED ALWAYS AS (
            CASE WHEN status = 'active' THEN client_id ELSE NULL END
        ) STORED,
    ADD UNIQUE KEY uq_storage_allocations_active_client (active_client_id);
