<?php

namespace App\Nova\Filters;

use App\Models\ValidatedEcTrack;
use App\Nova\Filters\Concerns\DeduplicatesOptionLabels;
use App\Services\StageProgressService;
use App\Support\UserDisplay;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\User;

/**
 * Camminatore (oc:8676). Filtra su `validated_ec_tracks.user_id`, colonna
 * presente sia nelle righe della risorsa sia in quelle aggregate della Lens
 * (StageProgressService::summaryQuery() usa lo stesso alias): nessuna
 * distinzione fra i due contesti. Opzioni: utenti con almeno una
 * validazione visibile al richiedente.
 */
class ValidatedEcTrackUserFilter extends Filter
{
    use DeduplicatesOptionLabels;

    public function __construct()
    {
        $this->searchable();
    }

    public function name(): string
    {
        return __('User');
    }

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        if (! is_numeric($value)) {
            return $query;
        }

        return $query->where('validated_ec_tracks.user_id', (int) $value);
    }

    /**
     * Etichetta UserDisplay::short(), deduplicata da optionsFromLabels().
     *
     * @return array<string, int>
     */
    public function options(NovaRequest $request): array
    {
        $viewer = $request->user();

        if ($viewer === null) {
            return [];
        }

        $userIds = app(StageProgressService::class)
            ->scopeVisibleTo(ValidatedEcTrack::query(), $viewer)
            ->select('validated_ec_tracks.user_id');

        return $this->optionsFromLabels(
            User::whereIn('id', $userIds)->get()
                ->map(fn (User $user) => ['id' => $user->id, 'label' => UserDisplay::short($user)]),
        );
    }
}
