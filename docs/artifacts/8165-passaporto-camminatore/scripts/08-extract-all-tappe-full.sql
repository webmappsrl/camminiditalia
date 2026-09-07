\t on
\a
\o /tmp/all_tappe_full.json
SELECT json_agg(json_build_object(
  'id', et.id,
  'name', (et.name::json)->>'it',
  'geometry', ST_AsGeoJSON(ST_LineMerge(et.geometry::geometry))::json,
  'len_m', round(ST_Length(et.geometry::geography))
) ORDER BY et.id)
FROM ec_tracks et
JOIN layerables la ON la.layerable_type='App\Models\EcTrack' AND la.layerable_id=et.id
WHERE la.layer_id=9;
\o
