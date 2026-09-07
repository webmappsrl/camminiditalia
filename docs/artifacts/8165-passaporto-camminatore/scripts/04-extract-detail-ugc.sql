\t on
\a
\o /tmp/detail_ugc.json
SELECT json_object_agg(ec_track_id, ugc_list) FROM (
  SELECT et.id AS ec_track_id,
    json_agg(json_build_object(
      'id', ut.id,
      'geometry', ST_AsGeoJSON(ST_LineMerge(ut.geometry::geometry))::json
    )) AS ugc_list
  FROM ec_tracks et
  JOIN ugc_tracks ut ON ut.user_id = 3680 AND ST_DWithin(ut.geometry::geography, et.geometry::geography, 300)
  WHERE et.id IN (1, 679, 551)
  GROUP BY et.id
) x;
\o
