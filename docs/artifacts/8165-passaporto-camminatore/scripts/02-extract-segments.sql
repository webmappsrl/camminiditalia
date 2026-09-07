\t on
\a
\o /tmp/segments.json
WITH ugc_buf AS (
  SELECT ST_Union(ST_Buffer(geometry::geography, 50)::geometry) AS buf
  FROM ugc_tracks WHERE user_id = 3680
),
target_tappe AS (
  SELECT et.id, ST_LineMerge(et.geometry::geometry) AS geom, ST_Length(et.geometry::geography) AS len_m
  FROM ec_tracks et
  WHERE et.id IN (1, 679, 551)
),
seg_count AS (
  SELECT id, geom, len_m, GREATEST(1, CEIL(len_m / 400.0)::int) AS n_seg
  FROM target_tappe
),
segments AS (
  SELECT sc.id AS ec_track_id,
         gs AS seg_idx,
         ST_LineSubstring(sc.geom, gs::float / sc.n_seg, (gs+1)::float / sc.n_seg) AS seg_geom
  FROM seg_count sc, generate_series(0, sc.n_seg - 1) AS gs
)
SELECT json_object_agg(ec_track_id, segs) FROM (
  SELECT ec_track_id, json_agg(json_build_object(
    'seg_idx', seg_idx,
    'geometry', ST_AsGeoJSON(seg_geom)::json,
    'covered', ST_Intersects((SELECT buf FROM ugc_buf), seg_geom)
  ) ORDER BY seg_idx) AS segs
  FROM segments
  GROUP BY ec_track_id
) x;
\o
