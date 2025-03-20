<template>
    <div class="ag-header-container">
        <div class="ag-header-row">
            <div class="ag-header-cell" ref="eHeaderCell">
                <span>{{ params.displayName }}</span>
            </div>
        </div>
        <div class="ag-header-row toolbar">
            <button class="btn btn-primary" @click="save" :disabled="saving">
                {{ saving ? "Salvataggio..." : "Salva" }}
            </button>
        </div>
    </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import type { CustomHeaderProps } from "../../types/interfaces";

export default defineComponent({
    name: "CustomHeader",
    props: {
        params: {
            type: Object as () => CustomHeaderProps["params"],
            required: true,
        },
    },
    data() {
        return {
            saving: false,
        };
    },
    methods: {
        async save() {
            this.saving = true;
            try {
                await this.params.save();
            } finally {
                this.saving = false;
            }
        },
    },
});
</script>
