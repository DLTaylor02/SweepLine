ALTER TABLE road_candidates ADD COLUMN IF NOT EXISTS alternate_names jsonb NOT NULL DEFAULT '[]'::jsonb;
UPDATE road_candidates c SET alternate_names=COALESCE((
    SELECT jsonb_agg(names.label ORDER BY names.label) FROM (
        SELECT DISTINCT btrim(part) AS label
        FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id
        CROSS JOIN LATERAL unnest(ARRAY[s.name,s.ref,s.tags->>'alt_name',s.tags->>'official_name',s.tags->>'loc_name',s.tags->>'short_name',s.tags->>'old_name',s.tags->>'name:en']) raw(label)
        CROSS JOIN LATERAL regexp_split_to_table(raw.label,';') part
        WHERE m.candidate_id=c.id AND btrim(part)<>'' AND lower(btrim(part))<>lower(c.name)
    ) names
),'[]'::jsonb);
CREATE TABLE IF NOT EXISTS road_combination_cache (
    cache_key text PRIMARY KEY, snapshot jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS road_combinations_age ON road_combination_cache(created_at);
-- Saved snapshots remain private and usable after their source imports retire.
UPDATE favorites f SET snapshot=jsonb_set(f.snapshot,'{alternate_names}',COALESCE((
    SELECT jsonb_agg(names.label ORDER BY names.label) FROM (
        SELECT DISTINCT btrim(part) AS label
        FROM jsonb_array_elements(jsonb_build_array(f.snapshot) || CASE WHEN jsonb_typeof(f.snapshot->'parts')='array' THEN f.snapshot->'parts' ELSE '[]'::jsonb END) element
        CROSS JOIN LATERAL unnest(ARRAY[element->>'name',element->>'ref']) raw(label)
        CROSS JOIN LATERAL regexp_split_to_table(raw.label,';') part
        WHERE btrim(part)<>'' AND lower(btrim(part))<>lower(f.snapshot->>'name')
    ) names
),'[]'::jsonb),true) WHERE NOT (snapshot ? 'alternate_names');

