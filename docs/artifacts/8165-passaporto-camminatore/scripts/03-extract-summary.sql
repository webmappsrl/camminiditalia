\t on
\a
\o /tmp/summary.json
WITH ugc_buf AS (
  SELECT ST_Union(ST_Buffer(geometry::geography, 50)::geometry) AS buf
  FROM ugc_tracks WHERE user_id = 3680
),
tappe_seg AS (
  SELECT et.id,
         (et.name::json)->>'it' AS name,
         round(ST_Length(et.geometry::geography)) AS len_m,
         ST_LineMerge(et.geometry::geometry) AS geom,
         GREATEST(1, CEIL(ST_Length(et.geometry::geography) / 400.0)::int) AS n_seg
  FROM ec_tracks et
  JOIN layerables la ON la.layerable_type='App\Models\EcTrack' AND la.layerable_id=et.id
  WHERE la.layer_id=9
),
segs AS (
  SELECT t.id, t.name, t.len_m, t.n_seg, gs AS seg_idx,
         ST_LineSubstring(t.geom, gs::float / t.n_seg, (gs+1)::float / t.n_seg) AS seg_geom
  FROM tappe_seg t, generate_series(0, t.n_seg - 1) AS gs
),
base AS (
  SELECT id, name, len_m, n_seg,
         count(*) FILTER (WHERE ST_Intersects((SELECT buf FROM ugc_buf), seg_geom)) AS covered_segs
  FROM segs
  GROUP BY id, name, len_m, n_seg
)
SELECT json_agg(json_build_object(
  'id', id, 'name', name, 'len_m', len_m,
  'covered_m', round(len_m::numeric * covered_segs / n_seg)
) ORDER BY id) FROM base;
\o
