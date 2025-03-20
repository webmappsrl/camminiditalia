<template>
    <div class="ag-filter-wrapper" style="display: flex; align-items: center">
        <input
            type="text"
            v-model="filterText"
            class="ag-input-field-input ag-text-field-input"
            placeholder="Cerca..."
            @input="onFilterChanged"
            style="flex: 1"
        />
        <button
            v-if="filterText"
            @click="resetFilter"
            class="reset-button"
            style="
                margin-left: 4px;
                padding: 2px 6px;
                background: #e74444;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 12px;
            "
        >
            ✕
        </button>
    </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import type { NameFilterProps } from "../../types/interfaces";

export default defineComponent({
    name: "NameFilter",
    props: {
        params: {
            type: Object as () => NameFilterProps["params"],
            required: true,
        },
    },
    data() {
        return {
            filterText: "",
            timeout: null as number | null,
        };
    },
    methods: {
        isFilterActive(): boolean {
            return this.filterText != null && this.filterText !== "";
        },

        doesFilterPass(): boolean {
            return true;
        },

        getModel(): { filter: string } | null {
            return this.isFilterActive() ? { filter: this.filterText } : null;
        },

        setModel(model: { filter: string } | null): void {
            this.filterText = model ? model.filter : "";
        },

        onFilterChanged(): void {
            if (this.timeout) {
                clearTimeout(this.timeout);
            }
            this.timeout = window.setTimeout(() => {
                this.params.filterChangedCallback();
            }, 300);
        },

        resetFilter(): void {
            this.filterText = "";
            this.params.filterChangedCallback();
        },
    },
});
</script>
