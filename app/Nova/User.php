<?php

namespace App\Nova;

use Spatie\Permission\Traits\HasRoles;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\AbstractUserResource;
use Laravel\Nova\Auth\PasswordValidationRules;

class User extends AbstractUserResource
{
    use PasswordValidationRules;

    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\User>
     */
    public static $model = \App\Models\User::class;
}
