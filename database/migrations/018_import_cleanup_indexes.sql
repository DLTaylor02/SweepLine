-- Support cascades and anti-joins used when removing obsolete/cancelled imports.
CREATE INDEX IF NOT EXISTS candidate_segments_segment ON candidate_segments(segment_id);
CREATE INDEX IF NOT EXISTS data_tasks_import_job ON data_tasks(import_job_id);
CREATE INDEX IF NOT EXISTS regions_active_job ON regions(active_job_id);
CREATE INDEX IF NOT EXISTS import_jobs_region ON import_jobs(region_id);
CREATE INDEX IF NOT EXISTS import_jobs_cleanup_age ON import_jobs(created_at,id)
    WHERE status IN ('complete','failed');
