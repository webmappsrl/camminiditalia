<?php

namespace App\Nova\Actions;

use App\Exceptions\CertificationDecisionException;
use App\Models\CertificationRequest;
use App\Services\CertificationRequestService;
use App\Support\EcTrackLabel;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\BooleanGroup;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Decisione sulla richiesta di certificazione, in due passaggi (oc:8671).
 *
 * Questo è il primo passaggio: raccoglie esito, tappe e nota e NON scrive
 * nulla. handle() restituisce un secondo modale (componente Vue
 * CONFIRM_MODAL_COMPONENT, resources/js/nova/certification-decision-confirm.js) con
 * il riepilogo; solo il suo pulsante di conferma chiama
 * CertificationDecisionController, che esegue la decisione. Stesso pattern di
 * ImportTaxonomyWhere in wm-package.
 */
class DecideCertificationRequest extends Action
{
    public const CONFIRM_MODAL_COMPONENT = 'certification-decision-confirm-modal';

    public function __construct(private readonly ?CertificationRequest $target = null)
    {
        // Solo dal dettaglio: sulle righe della lista Nova calcolerebbe i campi
        // (tappe) per ogni richiesta pending della pagina.
        $this->sole()->onlyOnDetail();
    }

    /**
     * Richiesta su cui l'azione lavora quando la NovaRequest ne indica una sola
     * (`resources` è un array nella lista delle azioni, una stringa separata da
     * virgole nella POST di esecuzione), limitata a ciò che l'utente può vedere.
     */
    public static function targetFromRequest(NovaRequest $request): ?CertificationRequest
    {
        $resources = $request->input('resources');
        $ids = is_array($resources) ? $resources : explode(',', (string) $resources);
        $ids = array_values(array_filter(array_map('trim', $ids), fn ($id) => ctype_digit($id)));
        $user = $request->user();

        if (count($ids) !== 1 || $user === null) {
            return null;
        }

        return CertificationRequest::query()->visibleTo($user)->find((int) $ids[0]);
    }

    public function name(): string
    {
        return __('Decide request');
    }

    public function uriKey(): string
    {
        return 'decide-certification-request';
    }

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        if ($models->count() !== 1) {
            return Action::danger(__('Decide one request at a time.'));
        }

        /** @var CertificationRequest $request */
        $request = $models->first();

        try {
            $preview = app(CertificationRequestService::class)->previewDecision(
                $request,
                (string) $fields->get('outcome'),
                $this->selectedTrackIds($fields->get('tracks')),
                (bool) $fields->get('select_all'),
                $fields->get('decision_note'),
            );
        } catch (CertificationDecisionException $e) {
            return Action::danger($e->getMessage());
        }

        return ActionResponse::modal(self::CONFIRM_MODAL_COMPONENT, $preview + [
            'certification_request_id' => $request->id,
            'is_approve' => $preview['outcome'] === CertificationRequestService::OUTCOME_APPROVE,
            'labels' => $this->confirmLabels($preview),
        ]);
    }

    public function fields(NovaRequest $request): array
    {
        $service = app(CertificationRequestService::class);
        $target = $this->target;

        $fields = [
            Select::make(__('Outcome'), 'outcome')
                ->options([
                    CertificationRequestService::OUTCOME_APPROVE => __('Approve'),
                    CertificationRequestService::OUTCOME_REJECT => __('Reject'),
                ])
                ->rules('required'),
        ];

        if ($target !== null) {
            ['selectable' => $selectable, 'validated' => $already] = $service->tracksForDecision($target);

            if ($already->isNotEmpty()) {
                $fields[] = Text::make(__('Already validated'), 'already_validated')
                    ->default($already->map(fn ($track) => EcTrackLabel::for($track))->implode(', '))
                    ->readonly()
                    ->help(__('These stages are already validated for this walker and cannot be selected again.'));
            }

            $options = $selectable
                ->mapWithKeys(fn ($track) => [$track->id => EcTrackLabel::for($track)])
                ->all();

            $fields[] = Boolean::make(__('Select all stages'), 'select_all')
                ->hide()
                ->dependsOn(['outcome'], function (Boolean $field, NovaRequest $request, FormData $formData): void {
                    self::showOnlyWhenApproving($field, $formData);
                });

            $fields[] = BooleanGroup::make(__('Stages'), 'tracks')
                ->options($options)
                ->hide()
                ->dependsOn(['outcome'], function (BooleanGroup $field, NovaRequest $request, FormData $formData): void {
                    self::showOnlyWhenApproving($field, $formData);
                });
        }

        $fields[] = Textarea::make(__('Note for the walker'), 'decision_note')
            ->rules('nullable', 'string', 'max:'.CertificationRequest::DECISION_NOTE_MAX_LENGTH)
            ->help(__('Optional. It is sent to the walker by email.'));

        return $fields;
    }

    private static function showOnlyWhenApproving(Field $field, FormData $formData): void
    {
        $formData->get('outcome') === CertificationRequestService::OUTCOME_APPROVE ? $field->show() : $field->hide();
    }

    /**
     * @return array<int, int>
     */
    private function selectedTrackIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return collect(is_array($value) ? $value : [])
            ->filter(fn ($checked) => (bool) $checked)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Testi del secondo modale, già tradotti: il componente JS non ha accesso
     * al traduttore Laravel.
     *
     * @param  array{outcome: string, track_labels: array<int, string>, walker: string, route: string}  $preview
     * @return array<string, string>
     */
    private function confirmLabels(array $preview): array
    {
        $approve = $preview['outcome'] === CertificationRequestService::OUTCOME_APPROVE;

        return [
            'title' => __('Confirm decision'),
            'summary' => $approve
                ? __('You are approving :count stages for :walker (:route):', ['count' => count($preview['track_labels']), 'walker' => $preview['walker'], 'route' => $preview['route']])
                : __('You are rejecting the request from :walker (:route).', ['walker' => $preview['walker'], 'route' => $preview['route']]),
            'note' => __('Note for the walker'),
            'warning' => __('The decision cannot be changed afterwards and the walker will be notified by email.'),
            'cancel' => __('Cancel'),
            'confirm' => __('Confirm decision'),
            'confirming' => __('Saving...'),
            'close' => __('Close'),
            'unexpected_error' => __('Unexpected error while saving the decision.'),
        ];
    }
}
