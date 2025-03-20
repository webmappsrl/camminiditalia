<template>
    <div class="layer-feature-wrapper">
        <!-- Dialog di conferma -->
        <portal to="modals" v-if="showConfirmModal">
            <transition name="fade">
                <div class="fixed inset-0 z-50">
                    <div class="fixed inset-0 bg-80 opacity-50"></div>
                    <div
                        class="relative h-full overflow-hidden p-4 flex items-center justify-center"
                    >
                        <div
                            class="bg-white rounded-lg shadow-lg overflow-hidden w-full max-w-lg"
                        >
                            <div class="p-4 border-b border-40 bg-40">
                                <h3 class="text-90 font-normal text-xl">
                                    Conferma cambio modalità
                                </h3>
                            </div>
                            <div class="p-4">
                                <p class="text-80 leading-normal">
                                    Passando alla modalità automatica perderai
                                    tutte le selezioni manuali. Sei sicuro di
                                    voler continuare?
                                </p>
                            </div>
                            <div
                                class="bg-30 px-6 py-3 flex items-center justify-end"
                            >
                                <button
                                    class="btn btn-link dim cursor-pointer text-80 font-normal text-base ml-auto mr-6"
                                    @click="closeConfirmModal"
                                >
                                    Annulla
                                </button>
                                <button
                                    class="btn btn-default btn-danger"
                                    @click="confirmModeChange"
                                >
                                    Conferma
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </portal>

        <div class="mb-6">
            <h3 class="text-90 font-normal text-xl">
                Features del modello: {{ modelName }}
            </h3>
        </div>

        <div class="flex items-center mb-4">
            <div class="toggle-switch">
                <button
                    type="button"
                    class="toggle-button"
                    :class="{ 'toggle-button--active': isManual }"
                    @click="handleToggleClick"
                >
                    <span class="toggle-slider"></span>
                </button>
                <span class="label-text ml-2">
                    {{
                        isManual ? "Selezione Manuale" : "Selezione Automatica"
                    }}
                </span>
            </div>
        </div>

        <!-- Mostra la griglia solo in modalità manuale -->
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
            <ag-grid-vue
                ref="agGridRef"
                class="ag-theme-alpine"
                :columnDefs="columnDefs"
                :defaultColDef="defaultColDef"
                :rowSelection="rowSelection"
                :rowData="gridData"
                :rowHeight="25"
                :getRowId="getRowId"
                :suppressLoadingOverlay="false"
                :suppressNoRowsOverlay="false"
                :overlayLoadingTemplate="loadingTemplate"
                :overlayNoRowsTemplate="noRowsTemplate"
                :suppressRowClickSelection="true"
                :suppressCellSelection="true"
                @grid-ready="onGridReady"
                @first-data-rendered="onFirstDataRendered"
                @selection-changed="onSelectionChanged"
                @column-resized="onColumnResized"
                @filter-changed="onFilterChanged"
            />
        </div>
    </div>
</template>

<script lang="ts">
import { defineComponent, ref, watch } from "vue";
import { FormField, HandlesValidationErrors } from "laravel-nova";
import { AgGridVue } from "ag-grid-vue3";
import "ag-grid-community/styles/ag-grid.css";
import "ag-grid-community/styles/ag-theme-alpine.css";
import {
    ClientSideRowModelModule,
    ModuleRegistry,
    Grid,
    GridApi,
    TextFilter,
    NumberFilter,
} from "ag-grid-community";
import type {
    LayerFeatureProps,
    GridData,
    GridState,
    CustomHeaderProps,
    NameFilterProps,
} from "../types/interfaces";

ModuleRegistry.registerModules([ClientSideRowModelModule]);

// Componente per l'header con bottone salva
const CustomHeader = defineComponent({
    props: {
        params: {
            type: Object as () => CustomHeaderProps["params"],
            required: true,
        },
    },
    template: `
        <div class="ag-header-container">
            <div class="ag-header-row">
                <div class="ag-header-cell" ref="eHeaderCell">
                    <span>{{ params.displayName }}</span>
                </div>
            </div>
            <div class="ag-header-row toolbar">
                <button
                    class="btn btn-primary"
                    @click="save"
                    :disabled="saving"
                >
                    {{ saving ? 'Salvataggio...' : 'Salva' }}
                </button>
            </div>
        </div>
    `,
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

// Componente custom filter
const NameFilter = defineComponent({
    props: {
        params: {
            type: Object as () => NameFilterProps["params"],
            required: true,
        },
    },
    template: `
        <div class="ag-filter-wrapper" style="display: flex; align-items: center;">
            <input
                type="text"
                v-model="filterText"
                class="ag-input-field-input ag-text-field-input"
                placeholder="Cerca..."
                @input="onFilterChanged"
                style="flex: 1;"
            />
            <button
                v-if="filterText"
                @click="resetFilter"
                class="reset-button"
                style="margin-left: 4px; padding: 2px 6px; background: #e74444; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;"
            >
                ✕
            </button>
        </div>
    `,
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

export default defineComponent({
    name: "LayerFeature",
    components: {
        AgGridVue,
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
        const agGridRef = ref<any>(null);
        const gridApi = ref<any>(null);
        const isLoading = ref<boolean>(true);
        const editable = ref<boolean>(props.edit ?? false);
        const selectedIds = ref<number[]>(
            props.field?.selectedEcFeaturesIds || []
        );
        const model = ref<string | undefined>(props.field?.model);
        const modelName = ref<string | undefined>(props.field?.modelName);
        const gridData = ref<GridData[]>([]);
        const isSaving = ref<boolean>(false);
        const isManual = ref<boolean>(
            (props.field?.selectedEcFeaturesIds?.length ?? 0) > 0
        );
        const showConfirmModal = ref<boolean>(false);
        const pendingToggle = ref<boolean>(false);
        const searchQuery = ref<string>("");
        const searchTimeout = ref<number | null>(null);

        // Templates per gli stati della griglia
        const loadingTemplate =
            '<span class="ag-overlay-loading-center">Caricamento dati...</span>';
        const noRowsTemplate =
            '<span class="ag-overlay-no-rows-center">Nessun dato disponibile</span>';

        const columnDefs = ref([
            {
                field: "boolean",
                headerName: "✓",
                width: 50,
                checkboxSelection: true,
                headerCheckboxSelection: true,
                suppressSizeToFit: true,
                filter: false,
            },
            {
                field: "id",
                headerName: "ID",
                width: 80,
                suppressSizeToFit: true,
                filter: false,
            },
            {
                field: "name",
                headerName: "Name",
                flex: 1,
                minWidth: 200,
                filter: NameFilter,
            },
        ]);

        const defaultColDef = ref({
            sortable: true,
            resizable: true,
            suppressMenu: true,
            suppressRowClickSelection: true,
            filter: false,
            floatingFilter: true,
        });

        const rowSelection = "multiple";

        // Gestione dello stato della griglia
        const gridState = ref<GridState>({
            columnState: null,
            filterState: null,
            sortState: null,
        });

        // Log iniziale dei props
        console.log("Props iniziali:", {
            resourceId: props.resourceId,
            selectedEcFeaturesIds: props.field?.selectedEcFeaturesIds,
            model: props.field?.model,
            modelName: props.field?.modelName,
            edit: props.edit,
        });

        // Funzione per ottenere l'ID univoco della riga
        const getRowId = (params: { data: GridData }): number => params.data.id;

        const handleSearch = (): void => {
            if (searchTimeout.value) {
                clearTimeout(searchTimeout.value);
            }
            searchTimeout.value = window.setTimeout(() => {
                fetchFeatures();
            }, 300);
        };

        // Utility functions
        const buildFilterObject = (
            filterType: "include" | "exclude",
            selectedIds: number[],
            modelName: string | undefined,
            layerId: number | undefined
        ): Array<Record<string, any>> => {
            const filterKey =
                filterType === "include"
                    ? `features_include_ids_${modelName}`
                    : `features_exclude_ids_${modelName}`;

            return [
                { [filterKey]: selectedIds },
                { [`features_by_layer_${modelName}`]: layerId },
            ];
        };

        const buildApiUrl = (
            filterObject: Array<Record<string, any>>,
            searchValue = ""
        ): string => {
            const base64Filter = btoa(JSON.stringify(filterObject));
            const searchParam = searchValue ? `&search=${searchValue}` : "";
            return `/nova-api/ec-tracks?filters=${encodeURIComponent(
                base64Filter
            )}${searchParam}&perPage=100&trashed=&page=1$relationType=`;
        };

        const mapResourceToTrack = (
            resource: any,
            isSelected: boolean
        ): GridData => ({
            id: resource.id.value,
            name:
                resource.fields.find((f: any) => f.attribute === "name")
                    ?.value || "",
            isSelected,
        });

        const fetchTracks = async (url: string): Promise<any> => {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return await response.json();
        };

        const waitForGridApi = (
            callback: () => void,
            attempts = 0,
            maxAttempts = 10
        ) => {
            console.log(
                `Tentativo ${
                    attempts + 1
                } di ${maxAttempts} per accedere all'API della griglia`
            );
            if (gridApi.value) {
                console.log("API della griglia trovata, eseguo il callback");
                callback();
            } else if (attempts < maxAttempts) {
                console.log(
                    "API della griglia non ancora disponibile, riprovo tra 100ms"
                );
                setTimeout(
                    () => waitForGridApi(callback, attempts + 1, maxAttempts),
                    100
                );
            } else {
                console.log(
                    "API della griglia non disponibile dopo tutti i tentativi"
                );
            }
        };

        const restoreSelections = () => {
            console.log("=== RIPRISTINO SELEZIONI ===");
            const selectedIds = props.field.selectedEcFeaturesIds || [];
            console.log("ID da selezionare:", selectedIds);

            if (gridApi.value) {
                console.log("Deseleziono tutte le righe");
                gridApi.value.deselectAll();

                gridApi.value.forEachNode((node: any) => {
                    if (selectedIds.includes(node.data.id)) {
                        console.log("Seleziono riga:", node.data.id);
                        node.setSelected(true);
                    }
                });
                console.log("Ripristino selezioni completato");
            }
        };

        const onFirstDataRendered = (params: any): void => {
            console.log("=== FIRST DATA RENDERED ===");
            console.log("Dati renderizzati per la prima volta");

            const selectedIds = props.field.selectedEcFeaturesIds || [];
            console.log("ID da selezionare:", selectedIds);

            if (gridApi.value) {
                console.log("API disponibile, procedo con la selezione");
                gridApi.value.deselectAll();

                gridApi.value.forEachNode((node: any) => {
                    if (selectedIds.includes(node.data.id)) {
                        console.log("Seleziono riga:", node.data.id);
                        node.setSelected(true);
                    }
                });
                console.log("Selezione iniziale completata");
            } else {
                console.log("API non disponibile durante first-data-rendered");
            }
        };

        const fetchFeatures = async (
            filterModel: any = null
        ): Promise<void> => {
            try {
                console.log("=== INIZIO FETCH FEATURES ===");
                console.log("Filter Model:", filterModel);
                console.log("Selected IDs:", props.field.selectedEcFeaturesIds);

                isLoading.value = true;
                const modelName = props.field.modelName;
                const layerId = props.field.layerId;
                const selectedIds = props.field.selectedEcFeaturesIds || [];
                const searchValue = filterModel?.name?.filter || "";

                // Prima chiamata: Recupera le righe già selezionate
                const selectedFilters = [
                    { [`features_include_ids_${modelName}`]: selectedIds },
                    { [`features_by_layer_${modelName}`]: layerId },
                ];
                const selectedRowsUrl = buildApiUrl(
                    selectedFilters,
                    searchValue
                );
                console.log("URL righe selezionate:", selectedRowsUrl);
                const selectedResponse = await fetchTracks(selectedRowsUrl);
                console.log(
                    "Righe selezionate ricevute:",
                    selectedResponse.resources.length
                );

                // Mappa le righe selezionate con boolean: true
                const selectedRows = selectedResponse.resources.map(
                    (resource: any) => ({
                        id: resource.id.value,
                        name:
                            resource.fields.find(
                                (f: any) => f.attribute === "name"
                            )?.value || "",
                        boolean: true,
                    })
                );

                // Seconda chiamata: Recupera le righe selezionabili
                const unselectedFilters = [
                    { [`features_exclude_ids_${modelName}`]: selectedIds },
                    { [`features_by_layer_${modelName}`]: layerId },
                ];
                const unselectedRowsUrl = buildApiUrl(
                    unselectedFilters,
                    searchValue
                );
                console.log("URL righe non selezionate:", unselectedRowsUrl);
                const unselectedResponse = await fetchTracks(unselectedRowsUrl);
                console.log(
                    "Righe non selezionate ricevute:",
                    unselectedResponse.resources.length
                );

                // Mappa le righe selezionabili (senza boolean)
                const selectableRows = unselectedResponse.resources.map(
                    (resource: any) => ({
                        id: resource.id.value,
                        name:
                            resource.fields.find(
                                (f: any) => f.attribute === "name"
                            )?.value || "",
                    })
                );

                // Combina i risultati
                gridData.value = [...selectedRows, ...selectableRows];
                console.log(
                    "Totale righe nella griglia:",
                    gridData.value.length
                );
            } catch (error) {
                console.error("Error fetching features:", error);
                gridData.value = [];
                Nova.error("Errore durante il caricamento delle features");
            } finally {
                isLoading.value = false;
                console.log("=== FINE FETCH FEATURES ===");
            }
        };

        // Funzione per determinare se una riga è selezionabile
        const isRowSelectable = (params: any): boolean => {
            return true;
        };

        const onGridReady = (params: any): void => {
            console.log("=== GRID READY ===");
            gridApi.value = params.api;
            console.log("API griglia inizializzata");

            // Imposta le larghezze delle colonne
            gridApi.value.sizeColumnsToFit();
            console.log("Larghezze colonne impostate");
        };

        const onSelectionChanged = (params: any): void => {
            console.log("=== SELECTION CHANGED ===");
            if (!gridApi.value) {
                console.log("API non disponibile in selection changed");
                return;
            }

            // Ottieni le selezioni correnti dalla griglia
            const currentSelectedNodes = gridApi.value.getSelectedNodes();
            const currentSelectedIds = currentSelectedNodes.map(
                (node: any) => node.data.id
            );
            console.log("ID selezionati:", currentSelectedIds);

            // Aggiorna le selezioni
            selectedIds.value = currentSelectedIds;
            props.field.selectedEcFeaturesIds = currentSelectedIds;
            console.log("Selezioni aggiornate");
        };

        // Aggiungi l'handler per il cambio di filtro
        const onFilterChanged = (): void => {
            const filterModel = gridApi.value?.getFilterModel();
            fetchFeatures(filterModel);
        };

        // Inizializza i dati
        fetchFeatures();

        // Aggiorna quando cambia il resourceId
        watch(
            () => props.resourceId,
            () => {
                fetchFeatures();
            }
        );

        // Salva lo stato della griglia quando le colonne vengono ridimensionate
        const onColumnResized = (params: any): void => {
            // Temporaneamente disabilitato il salvataggio dello stato delle colonne
            // per evitare errori con le API di AG Grid
        };

        const handleSave = async (): Promise<void> => {
            try {
                isSaving.value = true;
                const selectedFeatureIds = selectedIds.value;

                console.log("=== LOG SALVATAGGIO ===");
                console.log("ID da salvare:", selectedFeatureIds);
                console.log("=========================");

                const layerId = props.field.layerId;
                await Nova.request().post(
                    `/nova-vendor/layer-features/sync/${layerId}`,
                    {
                        features: selectedFeatureIds,
                        model: props.field.model,
                    }
                );

                Nova.success("Features salvate con successo");
                props.field.value = selectedFeatureIds;
                props.field.selectedEcFeaturesIds = selectedFeatureIds;

                // Ricarica i dati della griglia
                await fetchFeatures();
            } catch (error) {
                console.error("Errore durante il salvataggio:", error);
                Nova.error("Errore durante il salvataggio delle features");
            } finally {
                isSaving.value = false;
            }
        };

        const statusBar = {
            statusPanels: [
                {
                    statusPanel: "agTotalRowCountComponent",
                    align: "left",
                },
                {
                    statusPanel: "customStatsComponent",
                    align: "right",
                },
            ],
        };

        const CustomStatsComponent = defineComponent({
            template: `
                <div class="ag-status-name-value">
                    <button class="btn btn-primary" @click="save">Salva</button>
                </div>
            `,
            methods: {
                save() {
                    this.params.api.handleSave();
                },
            },
        });

        // Registra il componente personalizzato
        if (gridApi.value) {
            gridApi.value.components.registerComponent(
                "customStatsComponent",
                CustomStatsComponent
            );
        }

        const handleToggleClick = async (): Promise<void> => {
            // Se stiamo passando da manuale ad automatico e ci sono selezioni
            if (isManual.value && selectedIds.value.length > 0) {
                showConfirmModal.value = true;
            } else {
                // Se stiamo passando da automatico a manuale
                const newState = !isManual.value;
                isManual.value = newState;

                if (newState) {
                    // Se stiamo passando a manuale, carichiamo subito i dati
                    await fetchFeatures();
                } else {
                    // Se stiamo passando ad automatico
                    await handleModeChange();
                }
            }
        };

        const closeConfirmModal = (): void => {
            showConfirmModal.value = false;
            isManual.value = true;
        };

        const confirmModeChange = async (): Promise<void> => {
            showConfirmModal.value = false;
            isManual.value = false;
            await handleModeChange();
        };

        const handleModeChange = async (): Promise<void> => {
            try {
                if (!isManual.value) {
                    isSaving.value = true;
                    const layerId = props.field.layerId;
                    const model = props.field.model;

                    await Nova.request().post(
                        `/nova-vendor/layer-features/sync/${layerId}`,
                        {
                            features: [],
                            model,
                        }
                    );

                    selectedIds.value = [];
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

        return {
            agGridRef,
            gridApi,
            columnDefs,
            defaultColDef,
            rowSelection,
            gridData,
            onGridReady,
            onSelectionChanged,
            onColumnResized,
            loadingTemplate,
            noRowsTemplate,
            isLoading,
            isRowSelectable,
            getRowId,
            handleSave,
            isSaving,
            statusBar,
            model,
            modelName,
            isManual,
            showConfirmModal,
            handleToggleClick,
            closeConfirmModal,
            confirmModeChange,
            onFilterChanged,
            onFirstDataRendered,
        };
    },
});
</script>

<style scoped>
.layer-feature-wrapper {
    position: relative;
    width: 100%;
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
