# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Comandi comuni

Tutti i comandi `php artisan` vanno eseguiti dentro il container Docker:

```bash
docker exec laravel-camminiditalia php artisan <comando>
docker exec laravel-camminiditalia php artisan migrate
docker exec laravel-camminiditalia php artisan tinker
```

Avviare l'ambiente di sviluppo (dentro il container):
```bash
composer run dev   # serve + horizon + vite + pail in concurrently
```

Eseguire i test:
```bash
docker exec laravel-camminiditalia php artisan test
docker exec laravel-camminiditalia php artisan test --filter=NomeTest
docker exec laravel-camminiditalia php artisan test tests/Feature/LayerPolicyTest.php
```

Formattare il codice:
```bash
docker exec laravel-camminiditalia composer format   # esegue Laravel Pint
```

## Architettura

### Submodule wm-package — riferimento

Vedi `wm-package/CLAUDE.md` per:
- Trappola `HasPackageFactory` nelle classi figlio (sovrascrivere `newFactory()`)
- Convenzioni documentazione feature del package (`docs/resources/`)

### Submodule wm-package

Il progetto usa `wm-package` come submodule Git (montato anche come volume Docker in `../wm-package`). Contiene:
- Modelli base: `UgcPoi`, `UgcTrack`, `Layer`, `App`, `User`, `EcPoi`, `EcTrack`
- Risorse Nova astratte: `AbstractUgcResource`, `AbstractEcResource`, ecc.
- Policy base, Filters, Actions, Observers riusabili

**Regola:** logica generica e riusabile → `wm-package`. Logica specifica di camminiditalia → repo principale sotto `app/`.

### Estensioni locali

Il repo principale estende il package con override specifici:

| File locale | Estende |
|---|---|
| `App\Models\User` | `Wm\WmPackage\Models\User` |
| `App\Models\EcPoi` | `Wm\WmPackage\Models\EcPoi` |
| `App\Nova\UgcPoi` | `Wm\WmPackage\Nova\UgcPoi` |
| `App\Nova\UgcTrack` | `Wm\WmPackage\Nova\UgcTrack` |
| `App\Nova\Layer` | `Wm\WmPackage\Nova\Layer` |
| `App\Observers\UgcObserver` | `Wm\WmPackage\Observers\UgcObserver` |

Esiste `App\Models\UgcPoi` che estende il modello base del package (aggiunge `read_at`). Le policy vengono registrate in `AppServiceProvider` via `Gate::policy()`.

### Policy e registrazione

Le policy si registrano in `app/Providers/AppServiceProvider.php`. Per sovrascrivere una policy del package senza modificarlo, creare `App\Policies\NomePolicy` e registrarla con `Gate::policy(WmModel::class, AppPolicy::class)`.

### Ruoli e permessi (Spatie)

| Ruolo | Descrizione |
|---|---|
| `Administrator` | Accesso completo al pannello |
| `Validator` | Gestore di un cammino — vede solo le proprie segnalazioni |
| `Guest` | Accesso limitato |

**Il "gestore di cammino" corrisponde al ruolo `Validator`.**
Non usare ruoli del package come `Editor` — non esistono in questo progetto.

Permessi disponibili: `validate source surveys`, `validate pois`, `validate tracks`, `manage roles and permissions`.

### UGC e form

I form UGC sono discriminati da `properties->form->id`:
- `"report"` → segnalazione (visibile ai Validator)
- `"poi"` → punto di interesse (visibile solo agli Administrator)

Il campo `layer_id` viene salvato in `properties` JSON (no FK dedicata): `properties->>'layer_id'`.

La relazione user → layer è `$user->layers()` (`HasMany` via `user_id` su tabella `layers`) — specifica di camminiditalia.

### Observer e notifiche

`App\Observers\UgcObserver` estende quello del package e, al `created`, legge `properties['layer_id']`, trova il Layer e dispatcha `SendUgcReportMailJob` per notificare via email i gestori del layer.

### Routing Nova custom

`NovaServiceProvider` sovrascrive la route `layer-features/{layerId}` del package con `LayerFeatureController` locale, per filtrare le tracce per utente loggato senza toccare il package.

## Feature disponibili

| Feature | Ticket | Moduli toccati | Note |
|---|---|---|---|
| Home tab layer sorting | oc:7644 | `App\Nova\Layer`, `resources/js/nova/config-home-sorter.js` | Sorting layer nella home tab via Nova |
| UGC email notifications | oc:7641 | `App\Observers\UgcObserver`, `App\Jobs\SendUgcReportMailJob` | Email al gestore del layer alla creazione di un UGC report |
| UGC filtro layer e read/unread | oc:7640 | `App\Nova\UgcPoi`, `App\Models\UgcPoi`, `App\Policies\UgcPoiPolicy`, `App\Nova\Actions\MarkAsRead`, `App\Nova\Actions\MarkAsUnread` | Validator vede solo segnalazioni dei propri layer; badge e action bulk letto/non letto |

## Decisioni architetturali

### UGC filtro layer e read/unread (oc:7640)
- `App\Nova\UgcPoi` deve dichiarare `public static $model = App\Models\UgcPoi::class` — senza questo override Nova usa il modello del package e le modifiche a campi non in `$fillable` del package vengono silenziosamente ignorate
- Le Nova Action che devono essere usabili dai Validator richiedono `$this->canRun(fn($request, $model) => true)` nel costruttore — Nova 5 chiama `filterByResourceAuthorization` (policy `update`) quando `runCallback` non è impostato
- `UgcPoiPolicy::before()` gestisce esplicitamente tutti i ruoli: Administrator → true, non-Validator → false, Validator → null (passa ai metodi specifici)
- Filtri Nova con ricerca: usare `Select::make()->searchable()->filterable()` nei `fields()` invece di classi Filter custom — è il pattern nativo Nova, produce un select con ricerca senza Vue custom. Nascondere con `->hideFromIndex()->hideFromDetail()->hideWhenCreating()->hideWhenUpdating()`.
- `$layer->name` restituisce stringa vuota per via del cast — usare sempre `$layer->getStringName()` per ottenere il nome leggibile

### UGC email notifications (oc:7641)
- L'observer locale estende quello del package invece di modificarlo — mantiene la compatibilità con gli aggiornamenti di wm-package
- `layer_id` letto da `properties` JSON, non da FK — coerente con la scelta architetturale del progetto
