ALTER TABLE road_candidates ADD COLUMN surface_status text NOT NULL DEFAULT 'unknown' CHECK(surface_status IN ('paved','unknown','unpaved'));
WITH statuses AS (
 SELECT m.candidate_id,CASE WHEN bool_or(lower(trim(coalesce(s.surface,''))) IN ('unpaved','gravel','fine_gravel','compacted','dirt','earth','ground','sand','mud','grass','pebblestone','rock','rocks','unsealed')) THEN 'unpaved'
 WHEN bool_and(lower(trim(coalesce(s.surface,''))) IN ('asphalt','paved','concrete','concrete:plates','concrete:lanes','chipseal','paving_stones','paving_stones:lanes','sett','cobblestone','unhewn_cobblestone')) THEN 'paved' ELSE 'unknown' END AS status
 FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id GROUP BY m.candidate_id
)
UPDATE road_candidates c SET surface_status=x.status,surface_known_paved=(x.status='paved'),
 metrics=c.metrics||jsonb_build_object('paved',x.status='paved','surface_status',x.status)
FROM statuses x WHERE x.candidate_id=c.id;
UPDATE road_scores s SET dimensions=jsonb_set(s.dimensions,'{surface}',to_jsonb(CASE WHEN c.surface_known_paved THEN 1.0 ELSE 0.2 END)),
confidence=round((0.35+CASE WHEN c.surface_known_paved THEN 0.25 ELSE 0 END+CASE WHEN c.speed_mph IS NOT NULL THEN 0.2 ELSE 0 END+0.2/(1+c.uncertain_controls))::numeric,3)
FROM road_candidates c WHERE c.id=s.candidate_id;
