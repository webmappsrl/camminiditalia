<template>
    <div class="layer-feature-wrapper">
        <ConfirmModal
            v-if="showConfirmModal"
            @confirm="confirmModeChange"
            @close="closeConfirmModal"
        />

        <div class="mb-6">
            <h3 class="text-90 font-normal text-xl">
                Features del modello: {{ modelName }}
            </h3>
        </div>

        <div class="flex items-center mb-4">
            <ToggleSwitch :is-manual="isManual" @toggle="handleToggleClick" />
        </div>

        <div v-if="isManual">
            <div class="flex justify-end mb-2">
                <button
                    type="button"
                    class="btn btn-primary"
                    @click="handleSave"
                    :disabled="isSaving"
                >
                    {{ isSaving ? "Salvataggio..." : "Salva" }}
                </button>
            </div>
            <div>
                <ag-grid-vue
                    ref="agGridRef"
                    class="ag-theme-alpine layer-feature-grid"
                    :columnDefs="columnDefs"
                    :defaultColDef="defaultColDef"
                    :rowData="gridData"
                    :rowHeight="25"
                    :getRowId="getRowId"
                    :suppressLoadingOverlay="false"
                    :suppressNoRowsOverlay="false"
                    :overlayLoadingTemplate="loadingTemplate"
                    :overlayNoRowsTemplate="noRowsTemplate"
                    :suppressRowClickSelection="true"
                    :suppressCellSelection="true"
                    :context="{
                        addToPersistentSelection,
                        removeFromPersistentSelection,
                    }"
                    @grid-ready="handleGridReady"
                    @first-data-rendered="onFirstDataRendered"
                    @filter-changed="onFilterChanged"
                />
            </div>
        </div>
    </div>
</template>

<script lang="ts">
import { defineComponent, ref, watch, onMounted } from "vue";
import { FormField, HandlesValidationErrors } from "laravel-nova";
import { AgGridVue } from "ag-grid-vue3";
import type { GridApi, IRowNode } from "ag-grid-community";
import type { LayerFeatureProps } from "../types/interfaces";
import { useFeatures } from "../composables/useFeatures";
import { useGrid } from "../composables/useGrid";
import ConfirmModal from "./layer-feature/ConfirmModal.vue";
import ToggleSwitch from "./layer-feature/ToggleSwitch.vue";
import CustomHeader from "./layer-feature/CustomHeader.vue";
import NameFilter from "./layer-feature/NameFilter.vue";
import "../styles/shared.css";

export default defineComponent({
    name: "LayerFeature",
    components: {
        AgGridVue,
        ConfirmModal,
        ToggleSwitch,
        CustomHeader,
        NameFilter,
    },
    mixins: [FormField, HandlesValidationErrors],
    props: {
        resourceName: { type: String, required: true },
        resourceId: { type: [Number, String], required: true },
        field: { type: Object, required: true },
        edit: { type: Boolean, default: true },
        value: { type: [Array, Object], default: () => [] },
    },
    setup(props: LayerFeatureProps) {
        const {
            isLoading,
            gridData,
            persistentSelectedIds,
            isSaving,
            fetchFeatures,
            handleSave,
            updateSelectedNodes,
            setGridApi,
            addToPersistentSelection,
            removeFromPersistentSelection,
        } = useFeatures(props);

        const {
            gridApi,
            columnDefs,
            defaultColDef,
            onGridReady: initGrid,
        } = useGrid();

        const isManual = ref<boolean>(
            (props.field?.selectedEcFeaturesIds?.length ?? 0) > 0
        );
        const showConfirmModal = ref<boolean>(false);
        const modelName = ref<string | undefined>(props.field?.modelName);

        onMounted(() => {
            const savedIds = props.field?.selectedEcFeaturesIds;
            if (Array.isArray(savedIds) && savedIds.length > 0) {
                persistentSelectedIds.value = savedIds;
                console.log(
                    "[Selection] Initialized with saved IDs:",
                    persistentSelectedIds.value
                );
            }
        });

        const handleGridReady = async (params: {
            api: GridApi;
        }): Promise<void> => {
            initGrid(params);
            setGridApi(params.api);

            if (isManual.value) {
                try {
                    await fetchFeatures();
                } catch (error) {
                    Nova.error(
                        "Errore durante l'inizializzazione della griglia"
                    );
                }
            }
        };

        const loadingTemplate =
            '<span class="ag-overlay-loading-center">Caricamento dati...</span>';
        const noRowsTemplate =
            '<span class="ag-overlay-no-rows-center">Nessun dato disponibile</span>';

        const getRowId = (params: { data: { id: number } }) => params.data.id;

        const onFirstDataRendered = () => {
            console.log(
                "onFirstDataRendered - Updating nodes with persistentSelectedIds:",
                persistentSelectedIds.value
            );
            if (gridApi.value) {
                updateSelectedNodes();
            } else {
                console.warn("onFirstDataRendered - GridApi not available");
            }
        };

        const onFilterChanged = async () => {
            if (!gridApi.value) return;
            try {
                await fetchFeatures(gridApi.value.getFilterModel());
            } catch (error) {
                Nova.error("Errore durante il filtraggio");
            }
        };

        const handleToggleClick = async () => {
            if (isManual.value && persistentSelectedIds.value.length > 0) {
                showConfirmModal.value = true;
            } else {
                isManual.value = !isManual.value;
                if (isManual.value) {
                    try {
                        await fetchFeatures();
                    } catch (error) {
                        console.error("Error during toggle mode:", error);
                    }
                } else {
                    await handleModeChange();
                }
            }
        };

        const closeConfirmModal = () => {
            showConfirmModal.value = false;
            isManual.value = true;
        };

        const confirmModeChange = async () => {
            showConfirmModal.value = false;
            isManual.value = false;
            await handleModeChange();
        };

        const handleModeChange = async () => {
            try {
                if (!isManual.value) {
                    isSaving.value = true;
                    const layerId = props.field.layerId;
                    await Nova.request().post(
                        `/nova-vendor/layer-features/sync/${layerId}`,
                        {
                            features: [],
                            model: props.field.model,
                        }
                    );
                    persistentSelectedIds.value = [];
                    props.field.value = [];
                    props.field.selectedEcFeaturesIds = [];
                    Nova.success("Modalità automatica attivata");
                }
            } catch (error) {
                console.error("Errore durante il cambio di modalità:", error);
                Nova.error("Errore durante il cambio di modalità");
                isManual.value = !isManual.value;
            } finally {
                isSaving.value = false;
            }
        };

        watch(
            () => props.resourceId,
            async (newId, oldId) => {
                console.log("ResourceId changed:", { newId, oldId });
                if (isManual.value) {
                    try {
                        await fetchFeatures();
                    } catch (error) {
                        console.error("Error during resourceId change:", error);
                    }
                }
            }
        );

        return {
            isLoading,
            gridData,
            isSaving,
            isManual,
            showConfirmModal,
            modelName,
            columnDefs,
            defaultColDef,
            loadingTemplate,
            noRowsTemplate,
            getRowId,
            handleSave,
            handleGridReady,
            onFirstDataRendered,
            onFilterChanged,
            handleToggleClick,
            closeConfirmModal,
            confirmModeChange,
            addToPersistentSelection,
            removeFromPersistentSelection,
        };
    },
});
</script>

<style scoped>
.layer-feature-wrapper {
    position: relative;
    width: 100%;
}

.layer-feature-grid {
    width: 100%;
    height: 500px;
}

.ag-theme-alpine {
    width: 100%;
    height: 500px;
    --ag-header-height: 30px;
    --ag-header-foreground-color: #000;
    --ag-header-background-color: #f8f9fa;
    --ag-row-hover-color: #f5f5f5;
    --ag-selected-row-background-color: #e7f4ff;
}

/* Stili per gli overlay */
.ag-overlay-loading-center,
.ag-overlay-no-rows-center {
    padding: 10px;
    color: #666;
    font-size: 14px;
}

.btn {
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-primary {
    background-color: #4099de;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    margin-right: 10px;
}

.btn-primary:hover {
    background-color: #357abd;
}

.flex {
    display: flex;
}

.justify-end {
    justify-content: flex-end;
}

.items-center {
    align-items: center;
}

.mt-4 {
    margin-top: 1rem;
}

.ag-header-container {
    display: flex;
    flex-direction: column;
}

.toolbar {
    display: flex;
    justify-content: flex-end;
    padding: 5px;
    border-bottom: 1px solid #ddd;
}

.mb-2 {
    margin-bottom: 0.5rem;
}

.btn-primary:hover:not(:disabled) {
    background-color: #357abd;
}

.toggle-switch {
    display: inline-flex;
    align-items: center;
    cursor: pointer;
}

.toggle-button {
    position: relative;
    width: 50px;
    height: 24px;
    background-color: #ccc;
    border-radius: 24px;
    border: none;
    padding: 0;
    cursor: pointer;
    transition: background-color 0.3s;
}

.toggle-button--active {
    background-color: #4099de;
}

.toggle-slider {
    position: absolute;
    top: 4px;
    left: 4px;
    width: 16px;
    height: 16px;
    background-color: white;
    border-radius: 50%;
    transition: transform 0.3s;
}

.toggle-button--active .toggle-slider {
    transform: translateX(26px);
}

.label-text {
    margin-left: 10px;
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.3s;
}

.fade-enter,
.fade-leave-to {
    opacity: 0;
}

.btn-danger {
    background-color: #e74444;
    color: white;
}

.btn-danger:hover {
    background-color: #e01e1e;
}

.btn-link {
    background: transparent;
    border: 0;
    color: #666;
    text-decoration: underline;
    padding: 0.5rem;
}

.btn-link:hover {
    color: #333;
}

.mb-6 {
    margin-bottom: 1.5rem;
}

.text-90 {
    color: var(--90);
}

.text-xl {
    font-size: 1.25rem;
    line-height: 1.75rem;
}

.font-normal {
    font-weight: 400;
}

.search-wrapper {
    position: relative;
}

.search-input {
    padding: 0.5rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
    width: 250px;
    font-size: 0.875rem;
    outline: none;
    transition: border-color 0.2s;
}

.search-input:focus {
    border-color: #4099de;
}

.justify-between {
    justify-content: space-between;
}
</style>
