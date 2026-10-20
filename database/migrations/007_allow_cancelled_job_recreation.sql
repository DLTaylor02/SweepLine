-- Cancellation stops an attempt, never future demand for its original area.
DROP TABLE IF EXISTS cancelled_task_keys;
-- Free unique area keys for still-stopping attempts; the worker tracks them by ID.
UPDATE data_tasks SET task_key='cancelled-task:'||id WHERE cancel_requested=true AND status='running';
