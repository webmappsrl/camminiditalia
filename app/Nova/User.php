<?php

namespace App\Nova;

use Laravel\Nova\Auth\PasswordValidationRules;
use Wm\WmPackage\Nova\AbstractUserResource;

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
