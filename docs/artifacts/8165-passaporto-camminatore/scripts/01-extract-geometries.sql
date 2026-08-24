\t on
\a
\o /tmp/data_overview.json
WITH ugc AS (
  SELECT id, ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_LineMerge(geometry::geometry), 0.0002))::json AS geojson
  FROM ugc_tracks WHERE user_id = 3680
),
tappe AS (
  SELECT et.id, (et.name::json)->>'it' AS name,
         ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_LineMerge(et.geometry::geometry), 0.0002))::json AS geojson,
         round(ST_Length(et.geometry::geography)) AS len_m
  FROM ec_tracks et
  JOIN layerables la ON la.layerable_type='App\Models\EcTrack' AND la.layerable_id=et.id
  WHERE la.layer_id=9
)
SELECT json_build_object(
  'ugc_tracks', (SELECT json_agg(json_build_object('id',id,'geometry',geojson)) FROM ugc),
  'tappe', (SELECT json_agg(json_build_object('id',id,'name',name,'geometry',geojson,'len_m',len_m) ORDER BY id) FROM tappe)
);
\o
