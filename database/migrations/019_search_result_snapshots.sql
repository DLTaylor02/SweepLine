ALTER TABLE search_history ADD COLUMN IF NOT EXISTS result_snapshot jsonb;
ALTER TABLE search_history ADD COLUMN IF NOT EXISTS snapshot_at timestamptz;
