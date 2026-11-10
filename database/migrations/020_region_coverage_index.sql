CREATE INDEX IF NOT EXISTS regions_active_coverage_idx ON regions USING gist(coverage) WHERE active_job_id IS NOT NULL;
