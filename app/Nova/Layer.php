<?php

namespace App\Nova;

use App\Enums\RouteShape;
use App\Models\User;
use App\Nova\Traits\FiltersUsersByRoleTrait;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Outl1ne\MultiselectField\Multiselect;
use Wm\WmPackage\Enums\OsmWalkingNetwork;
use Wm\WmPackage\Enums\Season;
use Wm\WmPackage\Nova\Actions\AddLayersToConfigHomeAction;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Actions\RegenerateLayerPbfAction;
use Wm\WmPackage\Nova\Layer as WmNovaLayer;

class Layer extends WmNovaLayer
{
    use FiltersUsersByRoleTrait;

    public static function indexQuery(NovaRequest $request, $query)
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user && ! $user->hasRole('Administrator')) {
            return $query->where('user_id', $user->id);
        }

        return $query;
    }

    public function fields(NovaRequest $request): array
    {
        $fields = parent::fields($request);
        $currentUser = $request->user();

        // Remove App field for all users
        $fields = array_filter($fields, function ($field) {
            return ! ($field instanceof BelongsTo && $field->attribute === 'appOwner');
        });

        // Attività e Taxonomy Where non sono esposte sui cammini: in
        // camminiditalia non vengono usate — la modalità automatica del layer
        // assegna gli EC per proprietario e non passa dalla tassonomia
        // (oc:8311). Verificato che nessun Layer ha valori impostati su queste
        // due relazioni, quindi la rimozione non nasconde dati esistenti.
        // Le relazioni restano sul modello: qui si nasconde solo il campo.
        $fields = array_filter($fields, function ($field) {
            return ! ($field instanceof MorphToMany
                && in_array($field->attribute, ['taxonomyActivities', 'taxonomyWheres'], true));
        });

        // Modify layerOwner field to be visible only to admins
        $fields = array_map(function ($field) use ($currentUser) {
            if ($field instanceof BelongsTo && $field->attribute === 'layerOwner') {
                $field->canSee(function () use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });
                $field->help('⚠️ Modificando il gestore, tutte le tracce e i POI associati a questo layer verranno automaticamente trasferiti al nuovo gestore.');
            }

            return $field;
        }, $fields);

        $fields = array_values($fields);

        // Panel che aggrega tutte le select/multiselect degli attributi del
        // cammino (Temi, Portata, Stagioni) in un unico blocco nel form di
        // edit. Temi usa lo stesso field Multiselect già usato per Stagioni
        // (invece della UI nativa Nova di attach/detach del MorphToMany, che
        // Nova non supporta se annidata in un Panel), sincronizzando la
        // relazione taxonomyThemes tramite ->belongsToMany().
        //
        // Il fillUsing nativo del pacchetto (MultiselectBelongsToSupport)
        // fa un sync() "cieco" dei valori ricevuti, senza validarli: un id
        // inesistente o un valore non numerico rompe la query invece di
        // essere scartato. Il fillUsing sotto resta minimale — filtra ai
        // soli id di Temi realmente esistenti e fa solo sync(), nessuna
        // ricerca per nome, nessuna creazione: i nuovi Temi si creano solo
        // dalla sezione Nova dedicata.
        // I tre campi manuali sono ->onlyOnForms(): nel detail le
        // caratteristiche del cammino sono mostrate dal pannello di sola
        // lettura "Route attributes" (properties->attributes), che presenta
        // insieme valori calcolati e manuali. Tenerli anche in detail
        // duplicherebbe l'informazione in due forme diverse.
        $manualAttributesPanel = Panel::make(__('Manual attributes'), [
            Multiselect::make(__('Themes'), 'taxonomyThemes')
                ->belongsToMany(TaxonomyTheme::class)
                ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                    return function () use ($model, $request, $requestAttribute) {
                        $values = $request->input($requestAttribute) ?: [];

                        if (is_string($values)) {
                            $decoded = json_decode($values, true);
                            $values = is_array($decoded) ? $decoded : [];
                        }

                        if (! is_array($values)) {
                            $values = [];
                        }

                        $ids = array_values(array_unique(array_map(
                            'intval',
                            array_filter($values, fn ($v) => is_numeric($v))
                        )));

                        $existingIds = $ids === []
                            ? []
                            : \Wm\WmPackage\Models\TaxonomyTheme::whereIn('id', $ids)->pluck('id')->all();

                        /** @var \Wm\WmPackage\Models\Layer $model */
                        $model->taxonomyThemes()->sync($existingIds);

                        // Nessun dispatch qui: il ricalcolo e' gia' accodato
                        // da LayerObserver::saved() nello stesso salvataggio, e
                        // il job ha $afterCommit = true, quindi il worker lo
                        // prende dopo il commit e vede questi temi.
                        //
                        // Accodarlo di nuovo qui causava un 500: Nova esegue
                        // questa closure DENTRO la sua transazione, e con
                        // CACHE_STORE=database il lock di unicita' e' una riga
                        // su cache_locks. Il secondo dispatch tentava un insert
                        // sulla stessa chiave -> violazione di chiave primaria
                        // -> transazione abortita da Postgres -> l'update di
                        // fallback in DatabaseLock::acquire() esplodeva con
                        // SQLSTATE 25P02.
                        //
                        // Un tema associato dal pannello "Layers Associate"
                        // della risorsa Tema non passa da qui: entra nel
                        // config al primo salvataggio successivo del cammino
                        // o al lancio dell'action bulk dalla App.
                    };
                })
                ->help(__('Themes associated with this route. Select an existing theme; to create a new theme use the Taxonomy Themes section.'))
                ->onlyOnForms(),

            Select::make(__('Walking network'), 'properties->attributes->walking_network')
                ->options(OsmWalkingNetwork::toArray())
                ->displayUsingLabels()
                ->nullable()
                ->hideFromIndex()
                // Il valore persistito è { value, name }: al form serve il
                // solo codice, altrimenti la voce corrente non risulta
                // selezionata. Accetta anche la vecchia forma a stringa nuda.
                ->resolveUsing(fn ($value) => self::attributeCode($value))
                // fillUsing restituisce una closure: Nova la esegue dopo il
                // salvataggio del modello (l'id esiste già), scrivendo via
                // LayerAttributesService::persistManualValue() con una
                // singola istruzione SQL — nessun save() Eloquent che
                // rilegga/riscriva l'intero blob properties (finestra di
                // concorrenza con un ricalcolo automatico concorrente) e
                // nessuna chiave "walking_network": null quando il campo è svuotato.
                ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                    $value = $request->input($requestAttribute);

                    if (! is_string($value) || $value === '') {
                        $value = null;
                    } elseif (OsmWalkingNetwork::tryFrom($value) === null) {
                        $value = null;
                    }

                    return function () use ($model, $value) {
                        /** @var \Wm\WmPackage\Models\Layer $model */
                        $service = app(\App\Services\LayerAttributesService::class);

                        // Persistito nella forma { value, name } come
                        // taxonomy_where: il consumer non deve tradurre gli
                        // enum da sé.
                        $enriched = $value === null
                            ? null
                            : $service->withTranslations(
                                $value,
                                fn (string $locale) => OsmWalkingNetwork::from($value)->labelIn($locale)
                            );

                        $service->persistManualValue($model, 'walking_network', $enriched);
                    };
                })
                ->help(__('Scale of the walking network this route belongs to (OSM network tag)'))
                ->onlyOnForms(),

            Multiselect::make(__('Seasons'), 'properties->attributes->season')
                ->options(Season::toArray())
                ->nullable()
                ->hideFromIndex()
                // Come per Portata: dalla forma { value, name } persistita si
                // torna alla lista di soli codici attesa dal componente.
                ->resolveUsing(fn ($value) => self::attributeCodes($value))
                // Il componente Vue del Multiselect sottomette il valore come stringa
                // JSON. Va normalizzato a lista vera: properties finisce nel config.json
                // e il consumer scorre una lista, non una stringa.
                //
                // fillUsing restituisce una closure eseguita da Nova dopo il
                // salvataggio del modello: la scrittura passa da
                // LayerAttributesService::persistManualValue() (singola
                // istruzione SQL) invece del save() Eloquent, per le stesse
                // ragioni del campo Network sopra.
                ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                    $value = $request->input($requestAttribute);

                    if (is_string($value)) {
                        $decoded = json_decode($value, true);
                        $value = is_array($decoded) ? $decoded : null;
                    }

                    if ($value === []) {
                        $value = null;
                    }

                    // Scarta valori non riconosciuti dall'enum, senza far esplodere nulla.
                    if (is_array($value)) {
                        $value = array_values(array_filter(
                            $value,
                            fn ($v) => is_string($v) && Season::tryFrom($v) !== null
                        ));
                        if ($value === []) {
                            $value = null;
                        }
                    }

                    return function () use ($model, $value) {
                        /** @var \Wm\WmPackage\Models\Layer $model */
                        $service = app(\App\Services\LayerAttributesService::class);

                        // Lista di { value, name }, come taxonomy_where: il
                        // consumer non deve tradurre gli enum da sé.
                        $enriched = $value === null
                            ? null
                            : array_map(
                                fn (string $v) => $service->withTranslations(
                                    $v,
                                    fn (string $locale) => Season::from($v)->labelIn($locale)
                                ),
                                $value
                            );

                        $service->persistManualValue($model, 'season', $enriched);
                    };
                })
                ->help(__('Seasons in which this route is preferably walked'))
                ->onlyOnForms(),
        ]);

        // Il pannello editabile va inserito subito dopo il pannello
        // "Proprietà" (prodotto da PropertiesPanel::makeWithModel() in
        // wm-package\Nova\Layer::fields()), non in coda. makeWithModel()
        // restituisce però sempre una Laravel\Nova\Panel "semplice" (mai
        // un'istanza di PropertiesPanel), quindi un instanceof non lo
        // distingue dagli altri Panel (Map, Ec Tracks, Ec Pois, Detail
        // Blocks) costruiti nello stesso metodo. L'unico criterio
        // strutturale — non basato sull'etichetta tradotta — è che, nel
        // codice attuale del package, SOLO quel pannello viene incatenato
        // con ->collapsible() (riga `PropertiesPanel::makeWithModel(...)
        // ->collapsible()`): nessun altro Panel di Layer::fields() lo
        // chiama. Se questa invariante dovesse cambiare in wm-package, il
        // fallback sotto (coda) evita comunque un errore silenzioso.
        // Il docblock di Wm\WmPackage\Nova\Layer::fields() dichiara il
        // valore di ritorno come lista di Field, quindi PHPStan
        // (treatPhpDocTypesAsCertain) considera staticamente impossibile
        // che un elemento dell'array sia un Panel e segnalerebbe un
        // instanceof/is_a diretto qui come sempre falso — pur essendo vero
        // a runtime (Panel è mescolato ai Field nell'array restituito da
        // Nova). Il controllo è isolato in un metodo con parametro `array`
        // non generico, che interrompe quella propagazione di tipo.
        $propertiesPanelIndex = $this->findCollapsablePanelIndex($fields);

        if ($propertiesPanelIndex !== null) {
            array_splice($fields, $propertiesPanelIndex + 1, 0, [$manualAttributesPanel]);
        } else {
            $fields[] = $manualAttributesPanel;
        }

        array_unshift($fields, Panel::make(__('Route attributes'), [
            Text::make(__('Route attributes'), 'attributes_state', function () {
                return $this->buildAttributesStateHtml();
            })
                ->asHtml()
                ->onlyOnDetail(),
        ]));

        return $fields;
    }

    /**
     * Indice, nell'array eterogeneo Field/Panel restituito da
     * parent::fields(), del pannello "Proprietà" prodotto da
     * PropertiesPanel::makeWithModel() in wm-package. Quel metodo
     * restituisce sempre una Laravel\Nova\Panel "semplice" (mai
     * un'istanza di PropertiesPanel), quindi non è distinguibile dagli
     * altri Panel per classe. Nel codice attuale del package è però
     * l'UNICO Panel di Layer::fields() incatenato con ->collapsible():
     * criterio strutturale, non basato sull'etichetta tradotta.
     *
     * Parametro tipizzato `array` (non un generic Field[]) di proposito:
     * interrompe la propagazione del tipo dichiarato dal docblock di
     * parent::fields(), che altrimenti farebbe considerare a PHPStan
     * l'instanceof Panel sempre falso (treatPhpDocTypesAsCertain).
     */
    private function findCollapsablePanelIndex(array $fields): ?int
    {
        foreach ($fields as $index => $field) {
            if ($field instanceof Panel && $field->collapsable === true) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Costruisce l'HTML di sola lettura che mostra lo stato di tutti e
     * sette gli attributi del cammino (calcolati + manuali), distinguendo tre
     * stati per ogni filtro: valore presente, calcolato ma non
     * disponibile (con motivo), manuale non impostato.
     *
     * Nessuna query pesante: i valori calcolati sono già in `properties`,
     * per i Temi si usa la relazione già caricata (o una sola query).
     */
    private function buildAttributesStateHtml(): string
    {
        /** @var \Wm\WmPackage\Models\Layer $layerModel */
        $layerModel = $this->resource;
        // $routeAttributes = sotto-oggetto properties->attributes (le
        // caratteristiche del cammino calcolate/manuali), non gli
        // attributi Eloquent del modello ($layerModel->getAttributes()).
        $properties = is_array($layerModel->properties ?? null) ? $layerModel->properties : [];
        $routeAttributes = is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [];

        $cards = [
            $this->renderDistanceCard($routeAttributes['distance'] ?? null),
            $this->renderStageCountCard($routeAttributes['stage_count'] ?? null),
            $this->renderShapeCard($routeAttributes['shape'] ?? null),
            $this->renderTaxonomyWhereCard($routeAttributes['taxonomy_where'] ?? null),
            $this->renderNetworkCard($routeAttributes['walking_network'] ?? null),
            $this->renderSeasonCard($routeAttributes['season'] ?? null),
            $this->renderThemesCard(),
        ];

        $style = <<<'CSS'
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(220px, 1fr));
            gap:12px;
            CSS;

        return sprintf('<div style="%s">%s</div>', $style, implode('', $cards));
    }

    /**
     * Card di stato per un singolo filtro.
     *
     * @param  'ok'|'warn'|'unset'|'error'  $status
     */
    private function renderCard(string $label, string $status, string $valueHtml): string
    {
        $palette = [
            'ok' => ['bg' => '#f0fdf4', 'border' => '#86efac', 'badge' => '#16a34a', 'icon' => '✓'],
            'warn' => ['bg' => '#fffbeb', 'border' => '#fde68a', 'badge' => '#d97706', 'icon' => '⚠'],
            'unset' => ['bg' => '#f8fafc', 'border' => '#e2e8f0', 'badge' => '#94a3b8', 'icon' => '–'],
            'error' => ['bg' => '#fef2f2', 'border' => '#fecaca', 'badge' => '#dc2626', 'icon' => '!'],
        ][$status];

        return sprintf(
            '<div style="background:%s;border:1px solid %s;border-radius:8px;padding:12px 14px;min-width:0;">'
                .'<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">'
                .'<span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%%;background:%s;color:#fff;font-size:12px;font-weight:700;flex-shrink:0;">%s</span>'
                .'<span style="font-weight:600;font-size:13px;color:#334155;">%s</span>'
                .'</div>'
                .'<div style="font-size:14px;color:#0f172a;line-height:1.5;word-break:break-word;">%s</div>'
                .'</div>',
            $palette['bg'],
            $palette['border'],
            $palette['badge'],
            $palette['icon'],
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            $valueHtml
        );
    }

    private function renderDistanceCard(mixed $distance): string
    {
        if (! is_numeric($distance)) {
            return $this->renderCard(__('Length'), 'warn', $this->notCalculableHtml(__('not computable: the stages carry no distance data')));
        }

        $km = (float) $distance;
        $formatted = floor($km) == $km ? (string) (int) $km : number_format($km, 1, ',', '.');

        return $this->renderCard(__('Length'), 'ok', '<strong>'.htmlspecialchars($formatted.' km', ENT_QUOTES, 'UTF-8').'</strong>');
    }

    private function renderStageCountCard(mixed $stageCount): string
    {
        if (! is_numeric($stageCount)) {
            return $this->renderCard(__('Stages'), 'warn', $this->notCalculableHtml(__('not computable: no stages associated')));
        }

        $stages = (int) $stageCount;
        $label = $stages === 1 ? __('1 stage') : __(':count stages', ['count' => $stages]);

        return $this->renderCard(__('Stages'), 'ok', '<strong>'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</strong>');
    }

    /**
     * Estrae il codice da un attributo persistito nella forma
     * `{ value, name }`, accettando anche la vecchia forma a stringa nuda
     * (dati scritti prima dell'aggiunta delle traduzioni).
     */
    private static function attributeCode(mixed $raw): ?string
    {
        if (is_string($raw)) {
            return $raw === '' ? null : $raw;
        }

        if (is_array($raw) && isset($raw['value']) && is_string($raw['value'])) {
            return $raw['value'] === '' ? null : $raw['value'];
        }

        return null;
    }

    /**
     * Versione lista di attributeCode(): scarta le voci non interpretabili.
     *
     * @return array<int, string>
     */
    private static function attributeCodes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $codes = [];

        foreach ($raw as $item) {
            $code = self::attributeCode($item);
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function renderShapeCard(mixed $type): string
    {
        $type = self::attributeCode($type);

        if ($type === null) {
            return $this->renderCard(__('Route shape'), 'warn', $this->notCalculableHtml(__('not computable: no stages associated')));
        }

        $enum = RouteShape::tryFrom($type);

        if ($enum === null) {
            return $this->renderCard(__('Route shape'), 'error', $this->unrecognizedHtml($type));
        }

        if ($enum === RouteShape::DISCONTINUOUS) {
            $valueHtml = '<strong>'.htmlspecialchars($enum->label(), ENT_QUOTES, 'UTF-8').'</strong>'
                .'<br><span style="color:#78716c;font-size:12px;">'
                .htmlspecialchars(__('the route segments are not connected to each other'), ENT_QUOTES, 'UTF-8')
                .'</span>';

            return $this->renderCard(__('Route shape'), 'warn', $valueHtml);
        }

        return $this->renderCard(__('Route shape'), 'ok', '<strong>'.htmlspecialchars($enum->label(), ENT_QUOTES, 'UTF-8').'</strong>');
    }

    private function renderTaxonomyWhereCard(mixed $wheres): string
    {
        if (! is_array($wheres) || $wheres === []) {
            return $this->renderCard(__('Regions'), 'warn', $this->notCalculableHtml(__('not computable: missing stage geometry, or area not resolved by OpenStreetMap')));
        }

        $regions = [];

        foreach ($wheres as $where) {
            if (! is_array($where)) {
                continue;
            }

            $name = $this->resolveGeoName($where['name'] ?? null);
            if ($name === '') {
                continue;
            }

            $regions[] = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        }

        if ($regions === []) {
            return $this->renderCard(__('Regions'), 'warn', $this->notCalculableHtml(__('no region detected by OpenStreetMap for the stage geometry')));
        }

        $badge = 'background:#e0f2fe;color:#0369a1;border-radius:999px;padding:2px 8px;font-size:12px;margin:2px 3px 0 0;display:inline-block;';
        $chips = implode('', array_map(fn ($n) => "<span style=\"$badge\">$n</span>", $regions));

        return $this->renderCard(__('Regions'), 'ok', $chips);
    }

    private function renderNetworkCard(mixed $network): string
    {
        $network = self::attributeCode($network);

        if ($network === null) {
            return $this->renderCard(__('Walking network'), 'unset', $this->notSetHtml());
        }

        $enum = OsmWalkingNetwork::tryFrom($network);

        if ($enum === null) {
            return $this->renderCard(__('Walking network'), 'error', $this->unrecognizedHtml($network));
        }

        return $this->renderCard(__('Walking network'), 'ok', '<strong>'.htmlspecialchars($enum->label(), ENT_QUOTES, 'UTF-8').'</strong>');
    }

    private function renderSeasonCard(mixed $seasons): string
    {
        if (! is_array($seasons) || $seasons === []) {
            return $this->renderCard(__('Seasons'), 'unset', $this->notSetHtml());
        }

        $chips = [];
        $hasUnrecognized = false;

        foreach ($seasons as $season) {
            $season = self::attributeCode($season);

            if ($season === null) {
                continue;
            }

            $enum = Season::tryFrom($season);

            if ($enum === null) {
                $hasUnrecognized = true;
                $chips[] = '<span style="background:#fee2e2;color:#b91c1c;border-radius:999px;padding:2px 8px;font-size:12px;margin:2px 3px 0 0;display:inline-block;">'.$this->unrecognizedHtml($season).'</span>';

                continue;
            }

            $chips[] = '<span style="background:#fef3c7;color:#92400e;border-radius:999px;padding:2px 8px;font-size:12px;margin:2px 3px 0 0;display:inline-block;">'.htmlspecialchars($enum->label(), ENT_QUOTES, 'UTF-8').'</span>';
        }

        if ($chips === []) {
            return $this->renderCard(__('Seasons'), 'unset', $this->notSetHtml());
        }

        return $this->renderCard(__('Seasons'), $hasUnrecognized ? 'error' : 'ok', implode('', $chips));
    }

    private function renderThemesCard(): string
    {
        /** @var \Wm\WmPackage\Models\Layer $model */
        $model = $this->resource;
        $themes = $model->relationLoaded('taxonomyThemes')
            ? $model->getRelation('taxonomyThemes')
            : $model->taxonomyThemes()->get();

        if ($themes === null || $themes->isEmpty()) {
            return $this->renderCard(__('Themes'), 'unset', $this->notSetHtml());
        }

        $names = $themes->map(function ($theme) {
            return $this->resolveLocalizedName($theme->getRawOriginal('name'));
        })->filter(fn ($name) => $name !== '')->values();

        if ($names->isEmpty()) {
            return $this->renderCard(__('Themes'), 'unset', $this->notSetHtml());
        }

        $chips = $names->map(fn ($name) => '<span style="background:#ede9fe;color:#6d28d9;border-radius:999px;padding:2px 8px;font-size:12px;margin:2px 3px 0 0;display:inline-block;">'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'</span>')->implode('');

        return $this->renderCard(__('Themes'), 'ok', $chips);
    }

    private function notCalculableHtml(string $reason): string
    {
        return sprintf(
            '<span style="color:#92400e;font-weight:600;">%s</span><br><span style="color:#78716c;font-size:12px;">%s</span>',
            htmlspecialchars(__('not computable'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')
        );
    }

    private function notSetHtml(): string
    {
        return sprintf('<span style="color:#94a3b8;font-style:italic;">%s</span>', htmlspecialchars(__('not set'), ENT_QUOTES, 'UTF-8'));
    }

    private function unrecognizedHtml(string $rawValue): string
    {
        return sprintf(
            '<span style="color:#b91c1c;font-weight:600;">%s</span> <span style="color:#78716c;font-size:12px;">(%s)</span>',
            htmlspecialchars($rawValue, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(__('unrecognized value'), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Risolve il nome di una regione/comune da `taxonomy_where`, con
     * priorità italiano -> inglese -> prima lingua disponibile (nessuna
     * priorità sulla lingua corrente: i nomi arrivano da OpenStreetMap,
     * non sono legati alla localizzazione dell'admin che guarda il
     * detail).
     */
    private function resolveGeoName(mixed $value): string
    {
        if (! is_array($value)) {
            return is_string($value) ? $value : '';
        }

        if (! empty($value['it']) && is_string($value['it'])) {
            return $value['it'];
        }

        if (! empty($value['en']) && is_string($value['en'])) {
            return $value['en'];
        }

        foreach ($value as $translation) {
            if (is_string($translation) && $translation !== '') {
                return $translation;
            }
        }

        return '';
    }

    /**
     * Risolve un valore di nome eventualmente tradotto (stringa JSON,
     * array con chiavi di lingua, o stringa semplice) in una stringa
     * leggibile, con la stessa priorità di fallback usata da
     * Wm\WmPackage\Models\Layer::getStringName(): lingua corrente ->
     * italiano -> inglese -> prima lingua disponibile.
     */
    private function resolveLocalizedName(mixed $value): string
    {
        if (is_string($value) && json_validate($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return '';
        }

        $currentLocale = app()->getLocale();

        if (! empty($value[$currentLocale]) && is_string($value[$currentLocale])) {
            return $value[$currentLocale];
        }

        if (! empty($value['it']) && is_string($value['it'])) {
            return $value['it'];
        }

        if (! empty($value['en']) && is_string($value['en'])) {
            return $value['en'];
        }

        foreach ($value as $translation) {
            if (is_string($translation) && $translation !== '') {
                return $translation;
            }
        }

        return '';
    }

    public function actions(NovaRequest $request): array
    {
        $actions = parent::actions($request);
        $currentUser = $request->user();

        // Filter actions to show only to administrators
        $actions = array_map(function ($action) use ($currentUser) {
            // Restrict RegenerateLayerPbfAction, ExecuteEcTrackDataChainAction and AddLayersToConfigHomeAction to administrators only
            if ($action instanceof RegenerateLayerPbfAction || $action instanceof ExecuteEcTrackDataChainAction || $action instanceof AddLayersToConfigHomeAction) {
                $action->canSee(function () use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });
                $action->canRun(function ($request, $model) use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });
            }

            return $action;
        }, $actions);

        return $actions;
    }

    protected function canSeeGlobalAnalyticsCard(NovaRequest $request): bool
    {
        $currentUser = $request->user();

        return $currentUser && $currentUser->hasRole('Administrator');
    }
}
