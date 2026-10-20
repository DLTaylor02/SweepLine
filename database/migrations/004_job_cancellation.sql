ALTER TABLE data_tasks DROP CONSTRAINT data_tasks_status_check;
ALTER TABLE data_tasks ADD CONSTRAINT data_tasks_status_check CHECK(status IN ('queued','running','retry','complete','failed','split','cancelled'));
ALTER TABLE data_tasks ADD COLUMN cancel_requested boolean NOT NULL DEFAULT false;
ALTER TABLE import_jobs ADD COLUMN cancel_requested boolean NOT NULL DEFAULT false;
