\t on
\a
\o /tmp/buffers.json
SELECT json_object_agg(ec_track_id, buf) FROM (
  SELECT et.id AS ec_track_id,
    ST_AsGeoJSON(ST_SimplifyPreserveTopology(
      ST_Union(ST_Buffer(ut.geometry::geography, 50)::geometry), 0.00003
    ))::json AS buf
  FROM ec_tracks et
  JOIN ugc_tracks ut ON ut.user_id = 3680 AND ST_DWithin(ut.geometry::geography, et.geometry::geography, 100)
  WHERE et.id IN (1, 679, 551)
  GROUP BY et.id
) x;
\o
