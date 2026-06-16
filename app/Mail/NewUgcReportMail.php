<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\GeometryComputationService;

class NewUgcReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $novaUrl;
    public ?string $coordinates;
    public array $formFields;
    public array $mediaUrls;

    public function __construct(
        public readonly GeometryModel $ugcPoi,
        public readonly Layer $layer,
        public readonly bool $noOwner = false,
    ) {
        $resourceName = $ugcPoi instanceof \Wm\WmPackage\Models\UgcPoi ? 'ugc-pois' : 'ugc-tracks';
        $this->novaUrl = rtrim(config('app.url'), '/').'/nova/resources/'.$resourceName.'/'.$ugcPoi->id;
        $this->coordinates = $this->extractCoordinates($ugcPoi);
        app()->setLocale('it');
        $this->formFields = $this->resolveFormFields($ugcPoi);
        $this->mediaUrls = $ugcPoi->getMedia()->map(fn($m) => $m->getUrl())->toArray();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuova segnalazione su '.$this->layer->getStringName(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-ugc-report',
        );
    }

    private function resolveFormFields(GeometryModel $ugc): array
    {
        $form = $ugc->properties['form'] ?? [];
        $skipKeys = ['id', 'index', 'layer_id'];
        $locale = app()->getLocale();

        $app = \Wm\WmPackage\Models\App::find($ugc->app_id);
        $formId = $form['id'] ?? null;
        $schema = ($app && $formId) ? ($app->acquisitionForms($formId) ?? []) : [];

        $fieldSchemas = collect($schema['fields'] ?? [])->keyBy('name');

        $fields = [];
        foreach ($form as $key => $value) {
            if (in_array($key, $skipKeys) || $value === null || $value === '') {
                continue;
            }

            $fieldSchema = $fieldSchemas->get($key);
            $rawLabel = $fieldSchema['label'] ?? null;
            $label = is_array($rawLabel)
                ? ($rawLabel[$locale] ?? $rawLabel['it'] ?? $rawLabel['en'] ?? ucwords(str_replace('_', ' ', $key)))
                : ($rawLabel ?? ucwords(str_replace('_', ' ', $key)));

            // Per i campi select, risolve il valore leggibile
            $displayValue = $value;
            if ($fieldSchema && ($fieldSchema['type'] ?? '') === 'select') {
                $match = collect($fieldSchema['values'] ?? [])->firstWhere('value', $value);
                if ($match) {
                    $vLabel = $match['label'] ?? $value;
                    $displayValue = is_array($vLabel)
                        ? ($vLabel[$locale] ?? $vLabel['it'] ?? $vLabel['en'] ?? $value)
                        : $vLabel;
                }
            }

            $fields[] = ['label' => $label, 'value' => $displayValue];
        }

        return $fields;
    }

    private function extractCoordinates(GeometryModel $ugc): ?string
    {
        try {
            $coords = GeometryComputationService::make()->getGeometryModelCoordinates($ugc);

            return number_format($coords->y, 6).', '.number_format($coords->x, 6);
        } catch (\Throwable) {
            return null;
        }
    }
}
