CREATE TABLE IF NOT EXISTS beamng_exports (
    id text PRIMARY KEY CHECK(id ~ '^[a-f0-9]{32}$'),
    user_id bigint NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
    fingerprint text NOT NULL, name text NOT NULL, payload jsonb NOT NULL,
    status text NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','complete','failed','cancelled','expired')),
    progress text NOT NULL DEFAULT 'Queued', error text, cancel_requested boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), expires_at timestamptz,
    file_bytes bigint, report jsonb
);
CREATE INDEX IF NOT EXISTS beamng_owner ON beamng_exports(user_id,created_at DESC);
CREATE INDEX IF NOT EXISTS beamng_queue ON beamng_exports(status,created_at);

