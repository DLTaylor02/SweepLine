CREATE TABLE search_history (
    id bigserial PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
    parameters jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX search_history_user_recent ON search_history(user_id,id DESC);
