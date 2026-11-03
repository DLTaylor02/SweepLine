CREATE TABLE IF NOT EXISTS custom_roads (
    id bigserial PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
    profile text NOT NULL REFERENCES driving_profiles(slug),
    snapshot jsonb NOT NULL,
    search_parameters jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS custom_roads_owner ON custom_roads(user_id,id);
