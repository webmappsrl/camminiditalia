\t on
\a
\o /tmp/segment_buffers.json
WITH ugc_buf AS (
  SELECT ST_Union(ST_Buffer(geometry::geography, 50)::geometry) AS buf
  FROM ugc_tracks WHERE user_id = 3680
),
target_tappe AS (
  SELECT et.id, ST_LineMerge(et.geometry::geometry) AS geom, ST_Length(et.geometry::geography) AS len_m
  FROM ec_tracks et WHERE et.id IN (1, 679, 551)
),
seg_count AS (
  SELECT id, geom, len_m, GREATEST(1, CEIL(len_m / 400.0)::int) AS n_seg
  FROM target_tappe
),
segments AS (
  SELECT sc.id AS ec_track_id, gs AS seg_idx,
         ST_LineSubstring(sc.geom, gs::float / sc.n_seg, (gs+1)::float / sc.n_seg) AS seg_geom
  FROM seg_count sc, generate_series(0, sc.n_seg - 1) AS gs
),
covered_segments AS (
  SELECT s.ec_track_id, s.seg_geom
  FROM segments s, ugc_buf b
  WHERE ST_Intersects(b.buf, s.seg_geom)
)
SELECT json_object_agg(ec_track_id, buf) FROM (
  SELECT ec_track_id,
    ST_AsGeoJSON(ST_SimplifyPreserveTopology(
      ST_Union(ST_Buffer(seg_geom::geography, 50)::geometry), 0.00003
    ))::json AS buf
  FROM covered_segments
  GROUP BY ec_track_id
) x;
\o
