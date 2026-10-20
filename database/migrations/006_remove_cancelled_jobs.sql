CREATE TABLE cancelled_task_keys (task_key text PRIMARY KEY,cancelled_at timestamptz NOT NULL DEFAULT now());
INSERT INTO cancelled_task_keys(task_key) SELECT task_key FROM data_tasks WHERE status='cancelled' OR cancel_requested=true ON CONFLICT DO NOTHING;
UPDATE import_jobs j SET cancel_requested=true FROM data_tasks t WHERE t.import_job_id=j.id AND (t.status='cancelled' OR t.cancel_requested=true) AND j.status<>'complete';
DELETE FROM data_tasks WHERE status='cancelled' OR (cancel_requested=true AND status<>'running');
DELETE FROM import_jobs j WHERE cancel_requested=true AND status NOT IN ('running','complete')
AND NOT EXISTS(SELECT 1 FROM regions r WHERE r.active_job_id=j.id)
AND NOT EXISTS(SELECT 1 FROM data_tasks t WHERE t.import_job_id=j.id);
