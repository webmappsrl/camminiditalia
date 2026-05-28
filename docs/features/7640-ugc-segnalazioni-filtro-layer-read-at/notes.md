> Ticket: oc:7640

# Notes — UGC segnalazioni: filtro per cammino e flag letto/non letto

## Deviazioni dal piano

- **Task 2 e Task 6 unificati:** il piano prevedeva di aggiornare `AppServiceProvider` in due task separati. È stato fatto in un unico passaggio.
- **`App\Nova\UgcPoi` mancava di `$model`:** il piano non includeva l'override di `public static $model`. Senza di esso Nova usava `\Wm\WmPackage\Models\UgcPoi` invece di `App\Models\UgcPoi`, causando il malfunzionamento silenzioso di `MarkAsRead/MarkAsUnread` (il `$fillable` del modello base non includeva `read_at`). Aggiunto `public static $model = \App\Models\UgcPoi::class` nella risorsa Nova.

## Bug trovati

- **Badge `->label()` incompatibile con Nova 5.7:** il closure `->label(fn($value, $resource) => ...)` riceve un solo argomento in Nova 5.7, non due. Rimosso `->label()` e semplificato `resolveUsing` per restituire direttamente la stringa leggibile.
- **Actions "not authorized":** `MarkAsRead` e `MarkAsUnread` restituivano "Sorry! You are not authorized" perché Nova 5 chiama `filterByResourceAuthorization` (policy `update`) quando `runCallback` non è impostato. Risolto aggiungendo `$this->canRun(fn($request, $model) => true)` nel costruttore di entrambe le action.
- **`update()` silenziosamente ignorato:** le action usavano `$model->update(['read_at' => ...])` ma il modello ricevuto da Nova era `\Wm\WmPackage\Models\UgcPoi` (senza `read_at` in `$fillable`), quindi l'update veniva ignorato. Risolto con `forceFill()->save()` e aggiungendo `public static $model` alla risorsa Nova.

## Decisioni

- **`forceFill` mantenuto:** anche dopo aver aggiunto `public static $model`, `forceFill` è stato mantenuto come difesa contro regressioni future (es. se `$model` venisse rimosso per errore).
- **`HasPackageFactory` non risale alle classi figlio:** il trait usa `get_called_class()` per costruire il path della factory, quindi `App\Models\UgcPoi::factory()` cercava `App\Database\Factories\UgcPoiFactory` inesistente. Risolto sovrascrivendo `newFactory()` nel modello locale. Documentato in `wm-package/CLAUDE.md`.

## Follow-up

- Nessuno.
