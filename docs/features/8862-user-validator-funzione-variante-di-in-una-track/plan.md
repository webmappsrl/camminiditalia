> Ticket: oc:8862

# «Variante di» per il Validator — piano di implementazione

> **Per chi esegue:** sub-skill richiesta `superpowers:subagent-driven-development` o
> `superpowers:executing-plans`. **Nessun commit, `git add` o branch durante l'esecuzione**: i
> commit indicati sono istruzioni per il dev, eseguite solo dopo il review-gate.

**Obiettivo:** il Validator può dichiarare in Nova le varianti delle sue tappe, scegliendo come
principale solo tappe sue; ogni rifiuto diventa un errore di validazione tradotto e non un 500.

**Architettura:** le tre regole del dominio passano in un metodo statico di `EcTrackVariant`,
usato dal guard `saving()` e dalla validazione del campo Nova. Il filtro per proprietario
(regola di permesso) resta in `App\Nova\EcTrack`. I controlli nuovi scattano solo quando il
valore cambia rispetto a quello salvato.

**Stack:** Laravel, Nova 5, Spatie Permission, PHPUnit (`DatabaseTransactions`, DB
`camminiditalia_testing`).

**Spec:** [overview.md](overview.md)

## Vincoli globali

- Solo repo principale camminiditalia: `wm-package` non si tocca.
- Test: `docker exec laravel-camminiditalia php artisan test --filter=<Nome>`.
- Ogni testo nuovo ha chiave base in inglese e traduzione in `resources/lang/{it,en,fr,es,de}.json`.
- L'Administrator mantiene le opzioni di oggi (tutte le tappe dei cammini della tappa).
- I campi User e Layers della EcTrack restano riservati all'Administrator.
- Commit: `feat(oc:8862): ...`.

## Punti da guardare in review

1. Validator che salva una tappa variante di una principale **altrui** senza toccare il campo:
   deve salvare senza errori (Task 3, `test_unchanged_foreign_main_passes_validation`).
2. Principale tolta dal cammino dopo il collegamento, valore invariato: nessun errore (Task 3,
   `test_unchanged_main_outside_routes_passes_validation`).
3. Id inventato mandato a mano da un Validator: errore di validazione, non 500 né salvataggio
   (Task 3, `test_validator_cannot_submit_a_stage_of_another_owner`).
4. Svuotare il campo: deve cancellare la riga anche se la principale era altrui (Task 3,
   `test_clearing_the_field_removes_the_variant`).
5. Guest: il campo non è visibile (Task 2, `test_the_field_is_hidden_from_a_guest`).

---

### Task 1: regole del dominio in un solo punto

**File:**
- Modifica: `app/Models/EcTrackVariant.php` (`booted()`, righe 37-55)
- Modifica: `resources/lang/{it,en,fr,es,de}.json`
- Test: `tests/Feature/EcTrackVariantTest.php`

**Interfacce:**
- Produce: `public static function violation(int $ecTrackId, int $mainEcTrackId): ?string`.
  Restituisce la **chiave di traduzione inglese** del primo rifiuto, o `null` se il collegamento
  è ammesso. Le chiavi, in quest'ordine di controllo:
  - `A stage cannot be a variant of itself.`
  - `The main stage is already a variant: variants live on a single level.`
  - `This stage is already the main stage of other variants: it cannot become a variant itself.`

- [ ] **Step 1: test che falliscono** in `EcTrackVariantTest`:
  `test_violation_rejects_a_stage_variant_of_itself` (`assertSame('A stage cannot be a variant of itself.', EcTrackVariant::violation($t->id, $t->id))`),
  `test_violation_rejects_a_main_that_is_already_a_variant`,
  `test_violation_rejects_a_stage_that_already_has_variants`,
  `test_violation_allows_a_valid_pair` (`assertNull`).
- [ ] **Step 2:** `--filter=EcTrackVariantTest` → i 4 nuovi falliscono (metodo assente).
- [ ] **Step 3:** implementare `violation()`; il guard `saving()` diventa
  `if ($key = static::violation(...)) throw new RuntimeException(__($key));`.
  Aggiungere le 3 chiavi nei 5 file di lingua (it: i testi italiani attuali del guard).
- [ ] **Step 4:** `--filter=EcTrackVariantTest` → tutti verdi, compresi i test esistenti che
  attendono `RuntimeException`.
- [ ] **Step 5 (commit, solo dopo il review-gate):** `feat(oc:8862): regole delle varianti in EcTrackVariant::violation()`

### Task 2: campo visibile al Validator e opzioni filtrate

**File:**
- Modifica: `app/Nova/EcTrack.php` (`variantField()` righe 140-142, `variantOptions()` righe 152-176)
- Test: `tests/Feature/EcTrackVariantFieldTest.php`

**Interfacce:**
- Produce: `private function variantOptions(): array` con la stessa firma; per un utente con
  ruolo Validator (e non Administrator) aggiunge `->where('ec_tracks.user_id', $user->id)` e
  unisce sempre la principale già salvata (`EcTrackVariant::whereKey($id)->value('main_ec_track_id')`),
  anche se altrui. Le opzioni ricevono l'utente da `variantField($currentUser)`.
- `canSee`: `$currentUser && $currentUser->hasAnyRole(['Administrator', 'Validator']) && ! $this->isVariantRoot()`.

- [ ] **Step 1: test che falliscono.** Nel `setUp` aggiungere `$this->validator` con ruolo
  Validator; rendere `variantField()`/`optionIds()` del test parametrici sull'utente
  (`?User $as = null`, default admin). Test:
  - `test_the_field_is_visible_to_a_validator_on_own_stage` (`authorizedToSee` true)
  - `test_the_field_is_hidden_from_a_guest` (false)
  - `test_validator_is_offered_only_own_stages` (tappa propria sorella presente, tappa dello stesso layer con `user_id` admin assente)
  - `test_admin_is_still_offered_stages_of_any_owner`
  - `test_validator_is_offered_the_current_foreign_main` (variante del Validator con principale dell'admin: l'id della principale è fra le opzioni)
- [ ] **Step 2:** `--filter=EcTrackVariantFieldTest` → i nuovi falliscono.
- [ ] **Step 3:** implementare `canSee` e il filtro in `variantOptions()`.
- [ ] **Step 4:** `--filter=EcTrackVariantFieldTest` → tutti verdi.
- [ ] **Step 5 (commit):** `feat(oc:8862): campo Variante di visibile al Validator sulle tappe proprie`

### Task 3: validazione lato server e salvataggio solo se cambia

**File:**
- Modifica: `app/Nova/EcTrack.php` (`variantField()`: `->rules(...)`, `fillUsing` righe 118-134)
- Modifica: `resources/lang/{it,en,fr,es,de}.json`
- Test: `tests/Feature/EcTrackVariantFieldTest.php`

**Interfacce:**
- Consuma: `EcTrackVariant::violation()` (Task 1), `variantOptions()` (Task 2).
- Nuova chiave: `The chosen main stage is not among the stages you can choose.`
  (it: «La tappa principale scelta non è fra quelle che puoi indicare.»).

Regola (closure Laravel `function ($attribute, $value, $fail)` passata a `->rules()`):
1. valore vuoto, o uguale alla principale salvata → nessun controllo;
2. id non presente in `array_keys($this->variantOptions())` → `$fail(__('The chosen main stage is not among the stages you can choose.'))`;
3. `EcTrackVariant::violation($trackId, (int) $value)` non nullo → `$fail(__($key))`.

`fillUsing`: se il valore è uguale alla principale salvata non scrive nulla; vuoto → delete;
altrimenti `updateOrCreate` come oggi.

- [ ] **Step 1: test che falliscono**, validando con
  `Validator::make(['variant_of_ec_track_id' => $value], $field->getUpdateRules($request))`
  (helper del test `validationErrors(EcTrack $track, $value, User $as): array`):
  - `test_validator_cannot_submit_a_stage_of_another_owner` (errore con la chiave nuova tradotta)
  - `test_admin_cannot_submit_a_stage_outside_the_routes`
  - `test_unchanged_foreign_main_passes_validation`
  - `test_unchanged_main_outside_routes_passes_validation` (collegamento creato, poi riga `layerables` della principale cancellata)
  - `test_a_main_that_became_a_variant_is_a_validation_error` (messaggio di `violation()`, non eccezione)
  - `test_clearing_the_field_removes_the_variant` (esegue la closure di `fill`; riga assente)
  - `test_unchanged_value_is_not_rewritten` (`updated_at` della riga invariato)
- [ ] **Step 2:** `--filter=EcTrackVariantFieldTest` → i nuovi falliscono.
- [ ] **Step 3:** implementare regola e `fillUsing`; chiave nuova nei 5 file di lingua.
- [ ] **Step 4:** `--filter=EcTrackVariant` → verdi `EcTrackVariantTest`, `EcTrackVariantFieldTest`;
  poi `--filter=StageProgressVariantsTest` verde.
- [ ] **Step 5:** `docker exec laravel-camminiditalia composer format`.
- [ ] **Step 6 (commit):** `feat(oc:8862): validazione del campo Variante di con errori tradotti`
