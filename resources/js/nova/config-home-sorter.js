(function () {
    const REQUEST_EVENT = 'wm:config-home-sort-request';
    const registry = new Map();

    function isConfigHomeFlexible(vm) {
        return Boolean(
            vm &&
                vm.currentField &&
                vm.currentField.attribute === 'config_home' &&
                Array.isArray(vm.order) &&
                vm.groups &&
                typeof vm.groups === 'object'
        );
    }

    function getRegistryKey(vm) {
        return [
            vm.resourceName || 'resource',
            vm.resourceId || 'create',
            vm.currentField.attribute,
        ].join(':');
    }

    function normalizeFieldAttribute(attribute, groupKey) {
        if (typeof attribute !== 'string') {
            return '';
        }

        const prefix = `${groupKey}__`;

        return attribute.startsWith(prefix) ? attribute.slice(prefix.length) : attribute;
    }

    function findLayerField(group) {
        if (!group || !Array.isArray(group.fields)) {
            return null;
        }

        return (
            group.fields.find((field) => normalizeFieldAttribute(field.attribute, group.key) === 'layer') ||
            group.fields.find((field) => field.name === 'Layer') ||
            null
        );
    }

    function getOptionLabel(field, selectedValue) {
        if (!field || !Array.isArray(field.options)) {
            return '';
        }

        const selected = field.options.find((option) => String(option.value) === String(selectedValue));

        return selected && typeof selected.label === 'string' ? selected.label : '';
    }

    function getLayerLabelFromDom(groupKey) {
        const groupElement = document.getElementById(groupKey);

        if (!groupElement) {
            return '';
        }

        const selectors = [
            '.multiselect__single',
            '[role="combobox"]',
            'input[type="search"]',
            'input:not([type="hidden"])',
        ];

        for (const selector of selectors) {
            const element = groupElement.querySelector(selector);

            if (!element) {
                continue;
            }

            const rawValue = 'value' in element ? element.value : element.textContent;
            const label = normalizeLabel(rawValue);

            if (label !== '') {
                return label;
            }
        }

        return '';
    }

    function normalizeLabel(value) {
        if (typeof value !== 'string') {
            return '';
        }

        return value.replace(/\s+/g, ' ').trim();
    }

    function getLayerSortLabel(vm, key) {
        const group = vm.groups[key];

        if (!group || group.name !== 'layer') {
            return '';
        }

        const layerField = findLayerField(group);
        const optionLabel = layerField ? getOptionLabel(layerField, layerField.value) : '';
        const domLabel = getLayerLabelFromDom(group.key);

        return normalizeLabel(optionLabel || domLabel || '');
    }

    function sortLayerSegments(vm) {
        const nextOrder = [];
        let hasChanges = false;
        let segment = [];

        const flushSegment = () => {
            if (segment.length === 0) {
                return;
            }

            const originalSegment = segment.slice();
            const sortedSegment = segment
                .map((key, index) => ({
                    key,
                    index,
                    label: getLayerSortLabel(vm, key),
                }))
                .sort((left, right) => {
                    const byLabel = left.label.localeCompare(right.label, 'it', {
                        sensitivity: 'base',
                        numeric: true,
                    });

                    return byLabel !== 0 ? byLabel : left.index - right.index;
                })
                .map((item) => item.key);

            if (sortedSegment.some((key, index) => key !== originalSegment[index])) {
                hasChanges = true;
            }

            nextOrder.push(...sortedSegment);
            segment = [];
        };

        vm.order.forEach((key) => {
            const group = vm.groups[key];

            if (group && group.name === 'layer') {
                segment.push(key);

                return;
            }

            flushSegment();
            nextOrder.push(key);
        });

        flushSegment();

        return { hasChanges, nextOrder };
    }

    function notify(type, message) {
        if (typeof Nova === 'undefined') {
            return;
        }

        const callback = Nova[type];

        if (typeof callback === 'function') {
            callback(message);
        }
    }

    function messageFor(event, key, fallback) {
        return event && event.detail && typeof event.detail[key] === 'string' && event.detail[key] !== ''
            ? event.detail[key]
            : fallback;
    }

    function handleSortRequest(event) {
        const targetAttribute =
            event && event.detail && typeof event.detail.attribute === 'string'
                ? event.detail.attribute
                : 'config_home';
        const sorters = Array.from(registry.values()).filter(
            (vm) => vm.currentField && vm.currentField.attribute === targetAttribute
        );
        const vm = sorters[sorters.length - 1];
        const errorMessage = messageFor(event, 'errorMessage', 'Unable to find the home content to sort.');
        const infoMessage = messageFor(event, 'infoMessage', 'Layers are already sorted within each group.');
        const successMessage = messageFor(event, 'successMessage', 'Layers sorted alphabetically within each group.');

        if (!vm) {
            notify('error', errorMessage);

            return;
        }

        const { hasChanges, nextOrder } = sortLayerSegments(vm);

        if (!hasChanges) {
            notify('info', infoMessage);

            return;
        }

        vm.order.splice(0, vm.order.length, ...nextOrder);
        notify('success', successMessage);
    }

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const trigger = event.target.closest('[data-config-home-sort-trigger="true"]');

        if (!trigger) {
            return;
        }

        event.preventDefault();
        window.dispatchEvent(
            new CustomEvent(REQUEST_EVENT, {
                detail: {
                    attribute: trigger.getAttribute('data-config-home-sort-attribute') || 'config_home',
                    errorMessage: trigger.getAttribute('data-config-home-sort-error') || '',
                    infoMessage: trigger.getAttribute('data-config-home-sort-info') || '',
                    successMessage: trigger.getAttribute('data-config-home-sort-success') || '',
                },
            })
        );
    });

    window.addEventListener(REQUEST_EVENT, handleSortRequest);

    Nova.booting((app) => {
        app.mixin({
            mounted() {
                if (isConfigHomeFlexible(this)) {
                    registry.set(getRegistryKey(this), this);
                }
            },

            beforeUnmount() {
                if (isConfigHomeFlexible(this)) {
                    registry.delete(getRegistryKey(this));
                }
            },
        });
    });
})();
