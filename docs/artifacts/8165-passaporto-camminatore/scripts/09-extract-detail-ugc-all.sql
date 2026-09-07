\t on
\a
\o /tmp/detail_ugc_all.json
SELECT json_object_agg(ec_track_id, ugc_list) FROM (
  SELECT et.id AS ec_track_id,
    json_agg(json_build_object(
      'id', ut.id,
      'geometry', ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_LineMerge(ut.geometry::geometry), 0.00002))::json
    )) AS ugc_list
  FROM ec_tracks et
  JOIN layerables la ON la.layerable_type='App\Models\EcTrack' AND la.layerable_id=et.id
  JOIN ugc_tracks ut ON ut.user_id = 3680 AND ST_DWithin(ut.geometry::geography, et.geometry::geography, 300)
  WHERE la.layer_id=9
  GROUP BY et.id
) x;
\o
