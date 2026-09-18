> Ticket: oc:8587

# Piano

1. **Branch**: creare `feature/oc-8587-validator-non-vede-ectrack-dopo-trasferimento-ownership-layer` da `develop` aggiornato (`git fetch`, `git checkout develop`, `git pull`, poi checkout -b).

2. **`App\Policies\EcTrackPolicy`** (nuovo, pattern `EcPoiPolicy`):
   - `before()`: Administrator → `true`, altrimenti `null`.
   - `viewAny()`: `true` per tutti (Guest incluso, bloccato solo dalla route Nova).
   - `view()`/`update()`/`delete()`: `$user->id === $ecTrack->user_id`.
   - `create()`: `! $user->hasRole('Guest')`.
   - `restore()`/`forceDelete()`: `false`.

3. **`AppServiceProvider`**: cambiare l'import da `Wm\WmPackage\Policies\EcTrackPolicy` a `App\Policies\EcTrackPolicy` (la riga `Gate::policy(EcTrack::class, EcTrackPolicy::class)` resta invariata).

4. **`tests/Feature/EcTrackPolicyTest.php`**: import locale, rinominare `test_ectrack_policy_is_registered` → `test_local_ectrack_policy_is_registered` con commento sul perché (stesso pattern `EcPoiPolicyTest`). I test esistenti (create/update/delete/view/viewAny per ruolo) restano validi as-is.

5. **`App\Nova\EcTrack::indexQuery()`** (nuovo override locale):
   ```php
   public static function indexQuery(NovaRequest $request, $query)
   {
       $user = $request->user();
       if ($user->hasRole('Administrator')) return $query;
       if ($user->hasRole('Validator')) return $query->where('user_id', $user->id);
       return $query->whereRaw('1=0');
   }
   ```

6. **`App\Nova\EcPoi::indexQuery()`**: stesso pattern del punto 5.

7. **`tests/Feature/EcTrackIndexQueryTest.php`** (nuovo): Administrator vede tutte le EcTrack; Validator vede solo le proprie (via `user_id`) anche dopo un trasferimento di ownership simulato (crea traccia, cambia `user_id` del layer/della traccia, verifica che il vecchio owner non la veda più e il nuovo sì); Validator senza tracce vede lista vuota; Guest vede lista vuota.

8. **`tests/Feature/EcPoiIndexQueryTest.php`** (nuovo): stesso schema del punto 7, adattato a EcPoi.

9. **Test di regressione UGC** — estendere `tests/Feature/UgcPoiIndexQueryTest.php` con uno scenario più ampio (più layer posseduti, più report, un layer estraneo con report) che formalizzi la verifica empirica già fatta manualmente in reverse-interaction, a protezione di un futuro cambio upstream come oc:8162.

10. **Documentazione**: aggiungere riga alla tabella "Feature disponibili" e una sezione "Decisioni architetturali" in `CLAUDE.md` per oc:8587 — causa (oc:8162), doppio fix (Policy Gate + `indexQuery` Nova), nota sulla dipendenza con oc:8120 (EcPoi sola lettura Validator, ora resa effettivamente visibile), nota sui 2 UgcPoi senza `layer_id` (rischio noto accettato), nota sul rischio cross-progetto già presente nel testo del ticket.

11. **Verifica**: `composer format` (Pint), poi suite completa `php artisan test` e `phpstan analyse` — zero errori/regressioni prima di procedere.

12. **Review-gate**: subagente cieco di code review sul diff + verifica PHPStan nel contesto principale.

13. **Commit/push/PR**: commit(s) con scope `fix(oc:8587): …`, push del branch, PR verso `develop` con riferimento al ticket e riepilogo dei due bug trovati (Policy + indexQuery).

14. **Pulizia**: proporre la cancellazione del branch/PR obsoleti `fix/oc-8162-ectrack-policy-validator-scoping` (PR #64, già chiusa) una volta che la nuova PR è aperta.
