<?php

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConfigHomeSorterScriptTest extends TestCase
{
    public function test_sorter_script_orders_only_consecutive_layer_groups_and_uses_trigger_messages(): void
    {
        $tempScript = tempnam(sys_get_temp_dir(), 'config-home-sorter-test-');
        $sorterPath = base_path('resources/js/nova/config-home-sorter.js');

        $harness = <<<'JS'
const fs = require('fs');

const sorterScript = fs.readFileSync(process.argv[2], 'utf8');
const documentListeners = {};
const windowListeners = {};
const mixins = [];
const notifications = [];

class MockElement {
    constructor(attributes = {}) {
        this.attributes = attributes;
    }

    closest(selector) {
        return selector === '[data-config-home-sort-trigger="true"]' ? this : null;
    }

    getAttribute(name) {
        return this.attributes[name] || null;
    }
}

global.Element = MockElement;
global.document = {
    addEventListener(type, handler) {
        documentListeners[type] = handler;
    },
    getElementById() {
        return null;
    },
};

global.window = {
    addEventListener(type, handler) {
        windowListeners[type] = handler;
    },
    dispatchEvent(event) {
        if (windowListeners[event.type]) {
            windowListeners[event.type](event);
        }
    },
};

global.CustomEvent = function CustomEvent(type, init = {}) {
    this.type = type;
    this.detail = init.detail;
};

global.Nova = {
    booting(callback) {
        callback({
            mixin(definition) {
                mixins.push(definition);
            },
        });
    },
    success(message) {
        notifications.push(['success', message]);
    },
    info(message) {
        notifications.push(['info', message]);
    },
    error(message) {
        notifications.push(['error', message]);
    },
};

eval(sorterScript);

const mixin = mixins[0];
const options = [
    { value: 1, label: 'Alpha' },
    { value: 2, label: 'Beta' },
    { value: 3, label: 'Charlie' },
    { value: 4, label: 'Delta' },
    { value: 5, label: 'Echo' },
];

const vm = {
    currentField: { attribute: 'config_home' },
    resourceName: 'apps',
    resourceId: 42,
    order: ['title-1', 'layer-c', 'layer-a', 'slug-1', 'layer-d', 'layer-b', 'title-2', 'layer-e'],
    groups: {
        'title-1': { key: 'title-1', name: 'title', fields: [] },
        'layer-c': { key: 'layer-c', name: 'layer', fields: [{ attribute: 'layer-c__layer', name: 'Layer', value: 3, options }] },
        'layer-a': { key: 'layer-a', name: 'layer', fields: [{ attribute: 'layer-a__layer', name: 'Layer', value: 1, options }] },
        'slug-1': { key: 'slug-1', name: 'slug', fields: [] },
        'layer-d': { key: 'layer-d', name: 'layer', fields: [{ attribute: 'layer-d__layer', name: 'Layer', value: 4, options }] },
        'layer-b': { key: 'layer-b', name: 'layer', fields: [{ attribute: 'layer-b__layer', name: 'Layer', value: 2, options }] },
        'title-2': { key: 'title-2', name: 'title', fields: [] },
        'layer-e': { key: 'layer-e', name: 'layer', fields: [{ attribute: 'layer-e__layer', name: 'Layer', value: 5, options }] },
    },
};

mixin.mounted.call(vm);

const clickEvent = {
    target: new MockElement({
        'data-config-home-sort-attribute': 'config_home',
        'data-config-home-sort-success': 'Sorted ok',
        'data-config-home-sort-info': 'Already sorted',
        'data-config-home-sort-error': 'Sorter missing',
    }),
    preventDefault() {},
};

documentListeners.click(clickEvent);
const firstOrder = [...vm.order];

documentListeners.click(clickEvent);

console.log(JSON.stringify({ firstOrder, notifications }));
JS;

        file_put_contents($tempScript, $harness);

        $process = new Process(['node', $tempScript, $sorterPath], base_path());
        $process->run();

        @unlink($tempScript);

        $this->assertTrue(
            $process->isSuccessful(),
            trim($process->getErrorOutput()."\n".$process->getOutput())
        );

        $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['title-1', 'layer-a', 'layer-c', 'slug-1', 'layer-b', 'layer-d', 'title-2', 'layer-e'],
            $result['firstOrder']
        );
        $this->assertSame(['success', 'Sorted ok'], $result['notifications'][0]);
        $this->assertSame(['info', 'Already sorted'], $result['notifications'][1]);
    }
}
