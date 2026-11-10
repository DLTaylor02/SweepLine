CREATE TABLE IF NOT EXISTS search_jobs (
 id bigserial PRIMARY KEY,user_id bigint NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
 fingerprint text NOT NULL,parameters jsonb NOT NULL,history_id bigint REFERENCES search_history(id) ON DELETE SET NULL,
 status text NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','complete','failed','cancelled')),
 progress text NOT NULL DEFAULT 'Waiting for the search worker',preparation jsonb,result jsonb,error text,cancel_requested boolean NOT NULL DEFAULT false,
 created_at timestamptz NOT NULL DEFAULT now(),updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS search_jobs_pending ON search_jobs(user_id,fingerprint) WHERE status IN ('queued','running') AND NOT cancel_requested;
CREATE INDEX IF NOT EXISTS search_jobs_queue ON search_jobs(status,id);
