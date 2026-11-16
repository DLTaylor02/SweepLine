ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS ramp_context_version integer NOT NULL DEFAULT 0;
