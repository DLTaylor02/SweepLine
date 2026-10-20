-- PostGIS is enabled by the installer with administrator privileges.
CREATE TABLE regions (
    id bigserial PRIMARY KEY, slug text NOT NULL UNIQUE, name text NOT NULL,
    active_job_id bigint, coverage geometry(Polygon,4326), imported_at timestamptz
);
CREATE TABLE import_jobs (
    id bigserial PRIMARY KEY, region_id bigint NOT NULL REFERENCES regions(id),
    status text NOT NULL CHECK(status IN ('queued','running','complete','failed')),
    source_path text NOT NULL, source_sha256 text, coverage geometry(Polygon,4326) NOT NULL,
    progress text NOT NULL DEFAULT '', error text, created_at timestamptz NOT NULL DEFAULT now(),
    started_at timestamptz, finished_at timestamptz
);
ALTER TABLE regions ADD CONSTRAINT regions_active_job_fk FOREIGN KEY(active_job_id) REFERENCES import_jobs(id);
CREATE INDEX import_jobs_queue ON import_jobs(status,id);
CREATE TABLE zip_codes (
    zip char(5) PRIMARY KEY CHECK(zip ~ '^[0-9]{5}$'), place text NOT NULL,
    state text NOT NULL, location geography(Point,4326) NOT NULL,
    source text NOT NULL DEFAULT 'GeoNames', updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX zip_location_idx ON zip_codes USING gist(location);
CREATE TABLE osm_nodes (
    job_id bigint NOT NULL REFERENCES import_jobs(id) ON DELETE CASCADE, osm_id bigint NOT NULL,
    lon double precision NOT NULL, lat double precision NOT NULL, tags jsonb NOT NULL,
    PRIMARY KEY(job_id,osm_id)
);
CREATE TABLE road_segments (
    id bigserial PRIMARY KEY, job_id bigint NOT NULL REFERENCES import_jobs(id) ON DELETE CASCADE,
    osm_id bigint NOT NULL, name text, ref text, highway text NOT NULL,
    direction smallint NOT NULL CHECK(direction IN (-1,0,1)), surface text, speed_mph real,
    node_ids jsonb NOT NULL, tags jsonb NOT NULL, geom geometry(LineString,4326) NOT NULL,
    UNIQUE(job_id,osm_id)
);
CREATE INDEX segments_job ON road_segments(job_id,id);
CREATE INDEX segments_geometry ON road_segments USING gist(geom);
CREATE TABLE road_connectivity (
    job_id bigint NOT NULL REFERENCES import_jobs(id) ON DELETE CASCADE,
    node_id bigint NOT NULL, segment_id bigint NOT NULL REFERENCES road_segments(id) ON DELETE CASCADE,
    point_index integer NOT NULL, PRIMARY KEY(job_id,node_id,segment_id)
);
CREATE INDEX connectivity_segment ON road_connectivity(segment_id);
CREATE TABLE road_candidates (
    id bigserial PRIMARY KEY, job_id bigint NOT NULL REFERENCES import_jobs(id) ON DELETE CASCADE,
    name text NOT NULL, ref text, highway text NOT NULL, surface_known_paved boolean NOT NULL,
    length_m double precision NOT NULL CHECK(length_m > 0), mandatory_stops integer NOT NULL,
    uncertain_controls integer NOT NULL, speed_mph real, metrics jsonb NOT NULL,
    elevation_gain_m real, elevation_loss_m real, max_grade real,
    geom geography(LineString,4326) NOT NULL
);
CREATE INDEX candidates_geo ON road_candidates USING gist(geom);
CREATE INDEX candidates_job ON road_candidates(job_id);
CREATE TABLE candidate_segments (
    candidate_id bigint NOT NULL REFERENCES road_candidates(id) ON DELETE CASCADE,
    segment_id bigint NOT NULL REFERENCES road_segments(id) ON DELETE CASCADE,
    position integer NOT NULL, reversed boolean NOT NULL,
    PRIMARY KEY(candidate_id,position)
);
CREATE TABLE driving_profiles (slug text PRIMARY KEY, definition jsonb NOT NULL);
CREATE TABLE road_scores (
    candidate_id bigint NOT NULL REFERENCES road_candidates(id) ON DELETE CASCADE,
    profile text NOT NULL REFERENCES driving_profiles(slug), score real NOT NULL CHECK(score BETWEEN 0 AND 100),
    confidence real NOT NULL CHECK(confidence BETWEEN 0 AND 1), dimensions jsonb NOT NULL,
    PRIMARY KEY(candidate_id,profile)
);
CREATE INDEX road_scores_ranking ON road_scores(profile,score DESC);
