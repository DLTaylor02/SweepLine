CREATE TABLE IF NOT EXISTS favorite_thumbnails (
 favorite_id bigint PRIMARY KEY REFERENCES favorites(id) ON DELETE CASCADE,
 svg text, next_attempt_at timestamptz NOT NULL DEFAULT now(), error text, updated_at timestamptz NOT NULL DEFAULT now()
);
