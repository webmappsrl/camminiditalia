<?php

namespace Tests\Feature;

use App\Nova\App as NovaAppResource;
use Illuminate\Support\Facades\App as AppFacade;
use Laravel\Nova\Fields\Heading;
use ReflectionMethod;
use Tests\TestCase;
use Wm\WmPackage\Models\App as PackageApp;

class AppHomeLayerSortButtonTest extends TestCase
{
    public function test_home_tab_includes_sort_trigger_before_config_home_field(): void
    {
        AppFacade::setLocale('en');

        $fields = $this->homeTabFields();
        $configHomeIndex = $this->fieldIndexByAttribute($fields, 'config_home');

        $this->assertNotFalse($configHomeIndex, 'The config_home field should exist in the home tab.');
        $this->assertGreaterThan(0, $configHomeIndex, 'The sort trigger should be inserted before config_home.');
        $this->assertInstanceOf(Heading::class, $fields[$configHomeIndex - 1]);

        $markup = $fields[$configHomeIndex - 1]->name;

        $this->assertStringContainsString('Sort Home Layers', $markup);
        $this->assertStringContainsString('Sort Layers A-Z', $markup);
        $this->assertStringContainsString('data-config-home-sort-trigger="true"', $markup);
        $this->assertStringContainsString('data-config-home-sort-attribute="config_home"', $markup);
        $this->assertStringContainsString(
            'data-config-home-sort-success="Layers sorted alphabetically within each group. Click Update to save the new order."',
            $markup
        );
    }

    public function test_home_tab_sort_trigger_uses_italian_translations(): void
    {
        AppFacade::setLocale('it');

        $fields = $this->homeTabFields();
        $configHomeIndex = $this->fieldIndexByAttribute($fields, 'config_home');

        $this->assertNotFalse($configHomeIndex, 'The config_home field should exist in the home tab.');

        $markup = $fields[$configHomeIndex - 1]->name;

        $this->assertStringContainsString('Ordina i layer della home', $markup);
        $this->assertStringContainsString('Ordina layer A-Z', $markup);
        $this->assertStringContainsString(
            'data-config-home-sort-info="I layer sono gia ordinati per ogni gruppo."',
            $markup
        );
        $this->assertStringContainsString(
            'Ordina alfabeticamente solo i box Layer consecutivi.',
            $markup
        );
    }

    private function homeTabFields(): array
    {
        $resource = new NovaAppResource(new PackageApp([
            'id' => 123,
            'name' => 'Test App',
        ]));

        $method = new ReflectionMethod($resource, 'home_tab');
        $method->setAccessible(true);

        return $method->invoke($resource);
    }

    private function fieldIndexByAttribute(array $fields, string $attribute): ?int
    {
        foreach ($fields as $index => $field) {
            if (($field->attribute ?? null) === $attribute) {
                return $index;
            }
        }

        return null;
    }
}
