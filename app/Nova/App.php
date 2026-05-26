<?php

namespace App\Nova;

use Laravel\Nova\Fields\Heading;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Nova\App as NovaApp;

class App extends NovaApp
{
    protected function home_tab(): array
    {
        $fields = parent::home_tab();
        $sortTrigger = Heading::make($this->configHomeSortTriggerMarkup())
            ->asHtml()
            ->onlyOnForms();

        foreach ($fields as $index => $field) {
            if ($field instanceof Flexible && $field->attribute === 'config_home') {
                array_splice($fields, $index, 0, [$sortTrigger]);

                return $fields;
            }
        }

        $fields[] = $sortTrigger;

        return $fields;
    }

    protected function configHomeSortTriggerMarkup(): string
    {
        $title = e(__('Sort Home Layers'));
        $description = e(__('Sort consecutive Layer boxes alphabetically. Other box types stay in place and you can still reorder everything manually after clicking.'));
        $buttonLabel = e(__('Sort Layers A-Z'));
        $errorMessage = e(__('Unable to find the home content to sort.'));
        $infoMessage = e(__('Layers are already sorted within each group.'));
        $successMessage = e(__('Layers sorted alphabetically within each group.'));

        return <<<HTML
            <div class="wm-config-home-sorter rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <div>
                    <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">{$title}</h3>
                    <p style="margin: 0.35rem 0 0; color: rgb(107 114 128);">
                        {$description}
                    </p>
                </div>
                <div style="margin-top: 0.85rem;">
                    <button
                        type="button"
                        data-config-home-sort-trigger="true"
                        data-config-home-sort-attribute="config_home"
                        data-config-home-sort-error="{$errorMessage}"
                        data-config-home-sort-info="{$infoMessage}"
                        data-config-home-sort-success="{$successMessage}"
                        class="cursor-pointer rounded-md bg-primary-500 px-4 py-2 font-semibold text-white shadow hover:bg-primary-400 focus:outline-none focus:ring"
                    >
                        {$buttonLabel}
                    </button>
                </div>
            </div>
        HTML;
    }
}
