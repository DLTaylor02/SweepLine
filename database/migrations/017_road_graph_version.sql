ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS analysis_version integer NOT NULL DEFAULT 1;
