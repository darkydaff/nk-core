ALTER TABLE jobs
ADD COLUMN attempts INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER status,
ADD COLUMN duration_ms INT(10) UNSIGNED NULL AFTER completed_at,
ADD COLUMN exit_code INT(10) NULL AFTER duration_ms,
ADD COLUMN error_summary TEXT NULL AFTER exit_code,
ADD COLUMN worker_hostname VARCHAR(255) NULL AFTER error_summary;

-- Optional: update job events if needed, but it already has level, message, payload, created_at.
-- Let's ensure job_events has an index on job_id for faster lookups.
CREATE INDEX idx_job_events_job_id ON job_events(job_id);
