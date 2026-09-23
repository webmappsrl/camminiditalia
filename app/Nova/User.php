<?php

namespace App\Nova;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Nova\Auth\PasswordValidationRules;
use Laravel\Nova\Http\Requests\NovaRequest;
use Spatie\Permission\Models\Role;
use Vyuldashev\NovaPermission\RoleBooleanGroup;
use Wm\WmPackage\Nova\AbstractUserResource;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class User extends AbstractUserResource
{
    use PasswordValidationRules;

    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\User>
     */
    public static $model = \App\Models\User::class;

    /**
     * Ruoli assegnabili da un Administrator non super-admin (oc:8623): mai 'Administrator',
     * che resta riservato ai super-admin in wm-package.super_admin_emails.
     *
     * @var array<int, string>
     */
    private const ADMINISTRATOR_MANAGEABLE_ROLES = ['Validator', 'Guest'];

    /**
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request)
    {
        return collect(parent::fields($request))
            ->map(fn ($field) => $field instanceof RoleBooleanGroup
                ? $this->allowAdministratorToManageRoles($field, $request)
                : $field)
            ->all();
    }

    /**
     * Sovrascrive readonly/options/fillUsing del campo Roles ereditato da AbstractUserResource:
     * oltre ai super-admin (invariato), lascia gestire i ruoli anche a chi ha il ruolo Spatie
     * Administrator, ma solo per Validator/Guest — mai per Administrator stesso, che resta
     * gestibile solo dai super-admin email-whitelist del package.
     */
    private function allowAdministratorToManageRoles(RoleBooleanGroup $field, NovaRequest $request): RoleBooleanGroup
    {
        return $field
            ->readonly(fn (NovaRequest $request) => ! $this->canManageRoles($request->user()))
            ->options(function () use ($request) {
                $allRoles = Role::pluck('name', 'name');

                if (RolesAndPermissionsService::allowsUser($request->user())) {
                    return $allRoles;
                }

                return $allRoles->only(self::ADMINISTRATOR_MANAGEABLE_ROLES);
            })
            ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) {
                /** @var \App\Models\User $model */
                if (! $this->canManageRoles($request->user())) {
                    return;
                }

                if (! $request->exists($requestAttribute)) {
                    return;
                }

                $decoded = json_decode($request[$requestAttribute], true);
                if (! is_array($decoded)) {
                    return;
                }

                $submittedRoles = collect($decoded)
                    ->filter(fn ($value) => $value)
                    ->keys();

                if (! RolesAndPermissionsService::allowsUser($request->user())) {
                    $preservedRoles = $model->getRoleNames()
                        ->reject(fn ($roleName) => in_array($roleName, self::ADMINISTRATOR_MANAGEABLE_ROLES, true));

                    $roles = $submittedRoles
                        ->intersect(self::ADMINISTRATOR_MANAGEABLE_ROLES)
                        ->merge($preservedRoles)
                        ->unique();

                    $model->syncRoles($roles->toArray());

                    return;
                }

                if ($request->user()->id === $model->id && $model->hasRole('Administrator')) {
                    $submittedRoles = $submittedRoles->merge(['Administrator'])->unique();
                }

                $model->syncRoles($submittedRoles->toArray());
            });
    }

    private function canManageRoles(?Authenticatable $user): bool
    {
        if (RolesAndPermissionsService::allowsUser($user)) {
            return true;
        }

        return $user !== null && $user->hasRole('Administrator');
    }
}
