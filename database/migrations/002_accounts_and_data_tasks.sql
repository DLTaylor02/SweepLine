CREATE TABLE app_settings (
    key text PRIMARY KEY, value text NOT NULL, updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE app_users (
    id bigserial PRIMARY KEY, email text NOT NULL UNIQUE, name text NOT NULL,
    password_hash text NOT NULL, role text NOT NULL CHECK(role IN ('superadmin','member')),
    active boolean NOT NULL DEFAULT true, session_version integer NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX one_initial_superadmin ON app_users(role) WHERE role='superadmin';
CREATE TABLE auth_attempts (
    key text PRIMARY KEY, attempts integer NOT NULL, window_start timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE import_jobs ADD COLUMN allow_empty boolean NOT NULL DEFAULT false;
CREATE TABLE data_tasks (
    id bigserial PRIMARY KEY, task_key text NOT NULL UNIQUE,
    kind text NOT NULL CHECK(kind IN ('zips','roads')),
    status text NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','retry','complete','failed','split')),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb, attempts integer NOT NULL DEFAULT 0,
    progress text NOT NULL DEFAULT 'Waiting for background worker', error text,
    import_job_id bigint REFERENCES import_jobs(id) ON DELETE SET NULL,
    next_attempt_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX data_tasks_due ON data_tasks(status,next_attempt_at,id);

-- Precomputed canonical geometry keys remove exact duplicates across cached cells.
ALTER TABLE road_candidates ADD COLUMN geometry_key text;
UPDATE road_candidates SET geometry_key=md5(encode(ST_AsEWKB(ST_Normalize(geom::geometry)),'hex'));
CREATE INDEX candidates_geometry_key ON road_candidates(geometry_key);
