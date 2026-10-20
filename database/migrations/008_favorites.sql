CREATE TABLE favorites (
 id bigserial PRIMARY KEY,user_id bigint NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
 geometry_key text NOT NULL,profile text NOT NULL CHECK(profile IN ('twisty','open')),
 snapshot jsonb NOT NULL,search_parameters jsonb NOT NULL,created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(user_id,geometry_key,profile)
);
CREATE INDEX favorites_user_recent ON favorites(user_id,id DESC);
