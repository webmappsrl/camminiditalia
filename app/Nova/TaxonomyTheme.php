<?php

namespace App\Nova;

use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\TaxonomyTheme as NovaTaxonomyTheme;

class TaxonomyTheme extends NovaTaxonomyTheme
{
    /**
     * Espone i cammini associati al tema al posto delle tracce.
     *
     * In camminiditalia i Temi si associano ai Layer (i cammini), non alle
     * singole EcTrack: il pannello "Tracks Associate" della risorsa del package
     * viene quindi rimosso e sostituito da quello dei Layer. La relazione
     * `layers()` esiste già sul modello base `Taxonomy` del package — nessuna
     * modifica al package necessaria.
     *
     * Entrambe le scelte vivono qui e non in `Wm\WmPackage\Nova\TaxonomyTheme`,
     * che è condivisa da tutti gli shard Webmapp: altrove l'associazione
     * Tema↔EcTrack è legittima e non va toccata.
     */
    public function fields(NovaRequest $request): array
    {
        $fields = array_values(array_filter(
            parent::fields($request),
            fn ($field) => ! ($field instanceof MorphToMany && $field->attribute === 'ecTracks')
        ));

        $fields[] = MorphToMany::make(__('Layers Associate'), 'layers', Layer::class)
            ->display('name');

        return $fields;
    }
}
