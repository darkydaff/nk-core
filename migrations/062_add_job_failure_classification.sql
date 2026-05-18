ALTER TABLE jobs
ADD COLUMN failure_category VARCHAR(50) NULL AFTER error_summary,
ADD COLUMN is_retryable BOOLEAN NULL AFTER failure_category,
ADD COLUMN severity VARCHAR(20) NULL AFTER is_retryable;
