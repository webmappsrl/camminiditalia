---
paths:
  - "app/Nova/**"
---

# Trappole: Nova

- `->help()` di un Field Nova non accetta una closure: `Field::jsonSerialize()` la manda al frontend senza eseguirla, serializzata come `{}`, e l'help sparisce senza errori — passa la stringa già calcolata e nei test leggi `jsonSerialize()['helpText']` (oc:8976)
