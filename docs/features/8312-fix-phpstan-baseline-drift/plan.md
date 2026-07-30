> Ticket: oc:8312

# Piano — Aggiornare phpstan-baseline.neon e risolvere drift PHPStan preesistente

Ordine di esecuzione: prima i fix di codice (task 1-5), poi la rigenerazione del baseline per ultima (task 6) — altrimenti il baseline includerebbe anche errori che stiamo per correggere. Tutti i comandi `php`/`artisan`/`vendor/bin` vanno eseguiti dentro il container: `docker exec laravel-camminiditalia <comando>`.

## Task 1 — Fix `HasLayerFilterAndLink.php` (closure `filterable()`)

File: `app/Nova/Traits/HasLayerFilterAndLink.php:81`

Allineare la firma della closure al contratto dichiarato da `Laravel\Nova\Fields\Filterable::filterable()` (4 parametri, ritorno `void`):

```php
->filterable(function (NovaRequest $request, $query, mixed $value, string $attribute) {
    $query->whereRaw("(properties->>'layer_id')::integer = ?", [(int) $value]);
})
```

Rimuovere il `return` — Nova scarta il valore di ritorno (`applyFilter()` chiama il callback via `call_user_func` senza usarne il risultato). `NovaRequest` è già importato nel file.

**Commit:** `fix(oc:8312): allinea firma closure Select::filterable() al contratto Nova`

## Task 2 — Fix `HasLayerFilterAndLinkTest.php` (classe anonima)

File: `tests/Feature/HasLayerFilterAndLinkTest.php` (dentro `setUp()`, dove viene istanziata la classe anonima)

La classe anonima usata per testare il trait deve dichiarare le due proprietà che il trait si aspetta da un vero Nova Resource, per riflettere correttamente il contratto (non solo per zittire PHPStan):

```php
$this->subject = new class
{
    use HasLayerFilterAndLink;

    public $properties = [];

    public static $model;
};
```

Nessun cambio alle asserzioni esistenti — i test continuano a esercitare solo `renderLayerLink()` (statico), che non usa queste proprietà.

**Commit:** stesso commit del Task 1 (stesso trait, stesso motivo)

## Task 3 — Fix `Nova/Layer.php` (docblock orfano)

File: `app/Nova/Layer.php:24`

Rimuovere il blocco docblock `/** * The model the resource corresponds to. * @var class-string<\App\Models\Layer> */` posizionato sopra `indexQuery()`. Verificato: `App\Models\Layer` non esiste nel repo (il modello Layer vive solo in `Wm\WmPackage\Models\Layer`); il docblock non è associato a nessuna property reale della classe, quindi non c'è nulla da re-immettere altrove.

**Commit:** `fix(oc:8312): rimuovi docblock @var orfano e classe inesistente in App\\Nova\\Layer`

## Task 4 — Fix `TaxonomyPoiTypePolicy.php` (return mancanti)

File: `app/Policies/TaxonomyPoiTypePolicy.php`

Aggiungere `return false;` esplicito ai tre metodi con corpo vuoto, coerente col pattern già usato da `create()`/`update()` nello stesso file:

```php
public function delete(User $user, TaxonomyPoiType|\Laravel\Nova\Resource $taxonomyPoiType)
{
    return false;
}

public function restore(User $user, TaxonomyPoiType|\Laravel\Nova\Resource $taxonomyPoiType)
{
    return false;
}

public function forceDelete(User $user, TaxonomyPoiType|\Laravel\Nova\Resource $taxonomyPoiType)
{
    return false;
}
```

Non toccare `view()`/`update()` (hanno già un comportamento e un native return type `: bool` coerenti) né il PHPDoc `@return Response|bool` esistente sopra ai metodi — resta invariato, la firma nativa già presente definisce il contratto reale.

**Commit:** `fix(oc:8312): rendi esplicito il deny in TaxonomyPoiTypePolicy::delete/restore/forceDelete`

(Commit separato dal Task 1/3: è l'unico cambio comportamentale del ciclo, va isolato per un rollback granulare — vedi overview.md → Rischi.)

## Task 5 — Fix test con asserzioni/chiamate obsolete

**`tests/Feature/AppHomeLayerSortButtonTest.php`** (righe 21, 44): sostituire
```php
$this->assertNotFalse($configHomeIndex, 'The config_home field should exist in the home tab.');
```
con
```php
$this->assertNotNull($configHomeIndex, 'The config_home field should exist in the home tab.');
```
(`fieldIndexByAttribute()` ritorna `?int`, mai `false` — l'asserzione originale è un residuo di un pattern basato su `array_search()`).

**`tests/Feature/LayerOwnershipTransferTest.php`** (righe 60, 144): sostituire le due chiamate a `Layer::manualEcPois()` con `ecPois()` (deprecato da oc:8139).

**Commit:** `fix(oc:8312): sostituisci assertNotFalse deprecato e manualEcPois() nei test`

## Task 6 — Rigenerare `phpstan-baseline.neon`

Con tutti i fix precedenti applicati, rilanciare l'analisi e rigenerare il baseline **in singolo processo** (necessario in questo ambiente Docker locale per evitare la race condition sulla cache Nette osservata con i worker paralleli):

```bash
docker exec laravel-camminiditalia rm -rf build/phpstan/cache
docker exec laravel-camminiditalia mkdir -p build/phpstan/cache
docker exec laravel-camminiditalia vendor/bin/phpstan analyse --generate-baseline --debug --memory-limit=2G
```

Verificare a mano il contenuto di `phpstan-baseline.neon` generato: deve contenere solo le 31 entry attese (21 migration + 10 wm-package), **senza** le 2 voci stale precedenti (`routes/console.php`, `tests/Feature/ExampleTest.php` — devono sparire automaticamente perché il tool rigenera l'elenco da zero in base agli errori realmente presenti).

**Commit:** `fix(oc:8312): rigenera phpstan-baseline.neon allineato al codice attuale`

(Commit separato e ultimo: isola il cambio "meccanico" — nessuna riga di codice applicativo — dai fix comportamentali dei task precedenti.)

## Task 7 — Verifica finale

```bash
docker exec laravel-camminiditalia vendor/bin/phpstan analyse --debug --memory-limit=2G
docker exec laravel-camminiditalia php artisan test
```

Criteri di completamento:
- [ ] `phpstan analyse` conclude con **0 errori** (nessun errore residuo, nessuna voce baseline non matchata)
- [ ] `php artisan test` passa integralmente (nessuna regressione, in particolare su `tests/Feature/LayerOwnershipTransferTest.php`, `tests/Feature/AppHomeLayerSortButtonTest.php`, `tests/Feature/HasLayerFilterAndLinkTest.php`, e la suite generale che esercita `TaxonomyPoiTypePolicy` indirettamente se presente)

Se uno dei due comandi fallisce, non procedere al commit di quel task — tornare al file interessato e correggere prima di rigenerare il baseline (Task 6 va sempre eseguito per ultimo, a fix applicati e verificati).
