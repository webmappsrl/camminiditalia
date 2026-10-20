---
paths:
  - "routes/api.php"
  - "app/Providers/AppServiceProvider.php"
---

# Trappole: rate limiting delle route API

- **`throttle:10,1` condivide il contatore fra tutte le route che lo usano**: la chiave è il solo
  utente, non la route. Una route nuova con `throttle:10,1` consuma le 10 richieste al minuto di
  certificazione e condivisione della tappa, e viceversa: l'utente prende 429 su un'azione che non
  ha ripetuto. Per una route nuova definisci un limiter con nome in `AppServiceProvider` con chiave
  propria (`RateLimiter::for('nome', fn ($r) => Limit::perMinute(10)->by('nome:'.($r->user()?->id ?: $r->ip())))`)
  e usa `throttle:nome` (oc:8703 `passport-route-share`, oc:8169 `experiences`).
