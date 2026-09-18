<?php

namespace App\Policies;

use App\Models\TaxonomyPoiType;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;

class TaxonomyPoiTypePolicy
{
    use HandlesAuthorization;

    /**
     * viewAny/view restano sempre autorizzati per chiunque (comportamento
     * preesistente, invariato da questo lavoro): prima di questo hook
     * ritornavano sempre true, prive di alcun controllo di ruolo. Per le
     * altre ability, l'Administrator bypassa tutto tranne "delete", per cui
     * la guardia "tipo ancora in uso" deve poter intervenire — pattern
     * mirror di App\Policies\UgcTrackPolicy::before() (oc:8575).
     */
    public function before(User $user, string $ability): ?bool
    {
        if (in_array($ability, ['viewAny', 'view'], true)) {
            return null;
        }

        if (! $user->hasRole('Administrator')) {
            return false;
        }

        if ($ability === 'delete') {
            return null;
        }

        return true;
    }

    /**
     * Determine whether the user can view any models.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        return true;

    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TaxonomyPoiType|\Laravel\Nova\Resource $taxonomyPoiType): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        return false;
        //
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TaxonomyPoiType|\Laravel\Nova\Resource $taxonomyPoiType): bool
    {
        return false;
    }

    /**
     * Raggiunto solo per l'Administrator (before() ritorna null solo per
     * questa ability): verifica che il tipo non sia ancora agganciato a
     * EcPoi/EcTrack/Layer prima di autorizzare, per evitare che il DELETE
     * fallisca con l'errore SQL del vincolo FK su
     * taxonomy_poi_typeables.taxonomy_poi_type_id.
     *
     * @return Response|bool
     */
    public function delete(User $user, TaxonomyPoiType|\Laravel\Nova\Resource $taxonomyPoiType)
    {
        if ($this->isTaxonomyPoiTypeInUse($taxonomyPoiType->id)) {
            return Response::deny(__('This POI type is still in use and cannot be deleted.'));
        }

        return true;
    }

    /**
     * Vero se esiste almeno una riga in taxonomy_poi_typeables per questo
     * tipo, a prescindere dal tipo morfico collegato (EcPoi, EcTrack o
     * Layer condividono la stessa tabella pivot). Interrogare la tabella
     * direttamente invece delle relazioni Eloquent evita il rischio di
     * mismatch tra FQCN locale e del package sulla colonna
     * taxonomy_poi_typeable_type (stesso problema noto in oc:8140).
     */
    private function isTaxonomyPoiTypeInUse(int $taxonomyPoiTypeId): bool
    {
        return DB::table('taxonomy_poi_typeables')
            ->where('taxonomy_poi_type_id', $taxonomyPoiTypeId)
            ->exists();
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return Response|bool
     */
    public function restore(User $user, TaxonomyPoiType|\Laravel\Nova\Resource $taxonomyPoiType)
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return Response|bool
     */
    public function forceDelete(User $user, TaxonomyPoiType|\Laravel\Nova\Resource $taxonomyPoiType)
    {
        return false;
    }
}
