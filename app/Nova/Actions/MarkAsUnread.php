<?php

namespace App\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class MarkAsUnread extends Action
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->canRun(fn ($request, $model) => true);
    }

    public function name(): string
    {
        return __('Segna come non letto');
    }

    public function handle(ActionFields $fields, Collection $models): void
    {
        foreach ($models as $model) {
            $model->forceFill(['read_at' => null])->save();
        }
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
