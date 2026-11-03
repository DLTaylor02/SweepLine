CREATE TABLE IF NOT EXISTS endpoint_elevations (
    point_key text PRIMARY KEY,
    elevation_m double precision CHECK (elevation_m BETWEEN -500 AND 10000),
    expires_at timestamptz NOT NULL
);
