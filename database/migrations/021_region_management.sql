ALTER TABLE data_tasks ADD COLUMN IF NOT EXISTS manual_priority boolean NOT NULL DEFAULT false;
CREATE TABLE IF NOT EXISTS region_operations (
    id bigserial PRIMARY KEY,
    region_key text NOT NULL,
    region_id bigint REFERENCES regions(id) ON DELETE SET NULL,
    task_ids jsonb NOT NULL DEFAULT '[]',
    status text NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','complete','failed')),
    progress text NOT NULL DEFAULT 'Discard queued',
    requested_by bigint REFERENCES app_users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS region_operations_pending ON region_operations(region_key) WHERE status IN ('queued','running');
CREATE INDEX IF NOT EXISTS data_tasks_manual_priority ON data_tasks(next_attempt_at,id) WHERE manual_priority AND status IN ('queued','retry') AND NOT cancel_requested;
CREATE INDEX IF NOT EXISTS region_operations_latest ON region_operations(region_key,id DESC);
CREATE INDEX IF NOT EXISTS road_combinations_members ON road_combination_cache USING gin ((snapshot->'member_ids') jsonb_path_ops);
