<?php

namespace App\Nova\Traits;

use Laravel\Nova\Http\Requests\NovaRequest;

trait HidesAppFromIndexTrait
{
    public function fields(NovaRequest $request): array
    {
        $fields = parent::fields($request);

        foreach ($fields as $field) {
            if ($field->attribute === 'app') {
                $field->hideFromIndex();
            }
        }

        return $fields;
    }
}
