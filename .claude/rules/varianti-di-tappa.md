---
paths:
  - "app/Nova/EcTrack.php"
  - "app/Models/EcTrackVariant.php"
  - "app/Observers/EcTrackVariantObserver.php"
  - "app/Jobs/**"
  - "app/Console/Commands/**"
  - "database/seeders/**"
---

# Trappole: varianti di tappa

- Non scrivere `ec_track_variants` col query builder (`whereKey()->delete()`, `DB::table(...)`): non emette eventi Eloquent, `EcTrackVariantObserver` non scatta e `stage_count`/`distance` del cammino restano vecchi fino al prossimo ricalcolo — usa il model (`EcTrackVariant::find($id)?->delete()`, `updateOrCreate`) (oc:8918)
