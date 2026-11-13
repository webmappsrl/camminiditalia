> Ticket: oc:8976

# Piano — Problema associazione "variante di" per new tracks

**Goal:** Alla creazione di una EcTrack in Nova da un Validator con un solo cammino, associare
automaticamente la tappa a quel layer così il Field «Variante di» ha opzioni; difese policy su
attach/detach layer; `help()` se la tappa non è in alcun cammino.

**Architecture:** Hook statico `App\Nova\EcTrack::afterCreate()` (stessa transazione di
`ResourceStoreController`, come documentato nel package per `TrailApplication`) che, solo per
Validator con esattamente un layer in `layers.user_id`, chiama `$track->layers()->attach($layerId)`.
Policy `attachLayer` / `detachLayer` sul modello EcTrack (pattern come `DiaryPolicy::attachUgcTrack`).
Il Field `Layers` resta `canSee` Administrator-only (test esistenti in `EcTrackLayersAttachableFieldTest`).

**Tech stack:** Laravel, Nova 5, PostgreSQL, Pest/PHPUnit feature test.

**Spec:** [overview.md](overview.md)

## Global Constraints

- Codice nel repo principale; nessuna modifica a `wm-package`.
- Identificatori in inglese; stringhe utente in `resources/lang/{it,en,fr,es,de}.json` (chiavi in inglese).
- Test con `Tests\TestCase` + `DatabaseTransactions`; comandi in container Docker: `docker exec <php-container> php artisan test --filter=...`.
- Commit testuali (non eseguiti dall'agente senza OK): `fix(oc:8976): ...` / `test(oc:8976): ...`.

## Review Focus

| Input / condizione | Comportamento atteso | Test |
|---|---|---|
| Validator, 1 layer, creazione Nova | Una riga in `layerables` verso quel layer | Task 2 |
| Administrator crea tappa | Nessun attach automatico | Task 2 |
| Validator, 0 layer | Nessun attach; `help()` su «Variante di» | Task 3–4 |
| Validator, 2+ layer (anomalia) | Nessun attach automatico | Task 2 |
| Validator tenta detach layer via policy | Negato | Task 1 |
| Factory / insert diretto EcTrack | Nessun attach (solo Nova `afterCreate`) | Task 2 (nota nel test) |

---

## Task 1 — Policy attach/detach layer

**Files:**
- Modify: `app/Policies/EcTrackPolicy.php`
- Test: `tests/Feature/EcTrackPolicyTest.php` (estendere se esiste) o nuovo `tests/Feature/EcTrackLayerAttachPolicyTest.php`

**Implementazione:**
- `attachLayer(User $user, EcTrack $ecTrack, Layer $layer): bool` — Administrator (via `before`) sì; Validator solo se `$layer->user_id === $user->id`.
- `detachLayer(...): bool` — Administrator sì; Validator sempre `false`.
- Import `Wm\WmPackage\Models\Layer`.

**Test:**
- Validator + layer proprio → `attachLayer` true; layer altrui → false.
- Validator → `detachLayer` false; Administrator → true (su tappa/layer di test).

Commit suggerito: `fix(oc:8976): EcTrackPolicy per attach e detach layer`

---

## Task 2 — `afterCreate()` con auto-attach

**Files:**
- Modify: `app/Nova/EcTrack.php`
- Test: `tests/Feature/EcTrackValidatorLayerAutoAttachTest.php` (nuovo)

**Implementazione:**
```php
public static function afterCreate(NovaRequest $request, \Illuminate\Database\Eloquent\Model $model): void
{
    parent::afterCreate($request, $model);

    $user = $request->user();
    if (! $user?->hasRole('Validator')) {
        return;
    }

    $layerIds = Layer::query()->where('user_id', $user->id)->pluck('id');
    if ($layerIds->count() !== 1) {
        return;
    }

    $model->layers()->attach($layerIds->first());
}
```

- Usare il modello configurato (`config('wm-package.ec_track_model')`) se serve tipo per assert.
- Non chiamare `parent::` se il package aggiungerà un `afterCreate` vuoto in futuro — oggi `Wm\WmPackage\Nova\EcTrack` non ne ha uno: verificare con `grep` prima del merge.

**Test** (Queue::fake, Http::fake come negli altri test Nova):
1. Validator con un layer → dopo `EcTrack::afterCreate($request, $track)` (NovaRequest con user Validator, track appena creata) → `layerables` contiene il layer.
2. Administrator → nessuna riga.
3. Validator con 0 layer → nessuna riga.
4. Validator con 2 layer → nessuna riga.
5. Opzionale: `variantOptions` non vuoto dopo auto-attach (stesso layer, almeno un'altra tappa nel layer).

Commit suggerito: `fix(oc:8976): associazione automatica al cammino alla creazione tappa Validator`

---

## Task 3 — `help()` su «Variante di» senza layer

**Files:**
- Modify: `app/Nova/EcTrack.php` (`variantField()`)

**Implementazione:**
- Se la tappa corrente non ha righe in `layerables` (stessa query di `variantOptions`, o metodo privato condiviso), concatenare o sostituire `help()` con messaggio dedicato.
- In detail/update; in creazione il campo è già `hideWhenCreating()`.

Commit suggerito: `fix(oc:8976): help Variante di quando la tappa non è in un cammino`

---

## Task 4 — Traduzioni

**Files:**
- Modify: `resources/lang/{it,en,fr,es,de}.json`

**Chiave suggerita:** `variant_of_requires_layer` (testo: associare prima la tappa a un cammino / route).

Commit suggerito: `fix(oc:8976): traduzioni help Variante di senza cammino`

---

## Task 5 — Test `help()` e regressione Field Layers

**Files:**
- Modify: `tests/Feature/EcTrackVariantFieldTest.php` o nuovo test dedicato
- Verifica: `EcTrackLayersAttachableFieldTest` resta verde (Layers nascosto al Validator)

**Test:**
- Tappa Validator senza `layerables` → il Field «Variante di» espone il testo tradotto nel meta/help.
- Tappa con layer → help standard invariato (o solo testo base, senza messaggio «serve cammino»).

Commit suggerito: `test(oc:8976): help Variante di e regressione auto-attach`

---

## Verifica finale

Nel container PHP:

```bash
docker exec $(docker ps --format "{{.Names}}" | grep "^php-" | head -1) php artisan test --filter='EcTrackValidatorLayerAutoAttach|EcTrackLayerAttach|EcTrackVariantField|EcTrackLayersAttachable'
```

Manuale (opzionale): login come Validator con un cammino → Nova → nuova EcTrack → detail → «Variante di» mostra le tappe del cammino.
