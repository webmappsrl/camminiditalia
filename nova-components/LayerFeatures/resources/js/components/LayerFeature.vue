<template>
    <ag-grid-vue
        ref="agGrid"
        class="ag-theme-alpine"
        @grid-ready="onGridReady"
        @selection-changed="onSelectionChanged"
        :columnDefs="colDefs"
        :defaultColDef="defaultColDef"
        :rowSelection="rowSelection"
        :rowData="ecFeatures"
        :rowHeight="25"
    />
</template>
<script>
import { FormField, HandlesValidationErrors } from "laravel-nova";
import { ref, computed, defineComponent, toRaw } from "vue";
import { AgGridVue } from "ag-grid-vue3";
import "ag-grid-community/styles/ag-grid.css";
import "ag-grid-community/styles/ag-theme-alpine.css";
import LangRenderer from "./LangRenderer.vue";
import {
    CheckboxEditorModule,
    ClientSideRowModelModule,
    provideGlobalGridOptions,
    ModuleRegistry,
    ValidationModule,
    RowSelectionModule,
    ColDef,
    RowSelectionOptions,
} from "ag-grid-community";
import "ag-grid-enterprise";
provideGlobalGridOptions({ theme: "legacy" });
ModuleRegistry.registerModules([
    ClientSideRowModelModule,
    CheckboxEditorModule,
    RowSelectionModule,
    ValidationModule /* Development Only */,
]);
export default defineComponent({
    components: {
        "ag-grid-vue": AgGridVue,
    },
    mixins: [FormField, HandlesValidationErrors],
    props: ["resourceName", "resourceId", "field", "value"],
    setup(props) {
        const agGrid = ref(null);
        // Ottieni le tracce (dati della tabella)
        const ecFeatures = computed(() => {
            const rawData = toRaw(props.field?.ecFeatures) || [];
            return rawData.sort((a, b) => {
                const aSelected = selectedEcFeaturesIds.value.includes(a.id)
                    ? 1
                    : 0;
                const bSelected = selectedEcFeaturesIds.value.includes(b.id)
                    ? 1
                    : 0;
                return bSelected - aSelected; // ✅ Porta i selezionati in alto
            });
        });
        const selectedEcFeaturesIds = computed(
            () => toRaw(props.field?.selectedEcFeaturesIds) || [],
        );
        console.log("Tracce:", ecFeatures.value);

        console.log(
            "Tracce selezionate inizialmente:",
            selectedEcFeaturesIds.value,
        );

        const colDefs = ref([
            {
                field: "boolean",
                headerName: "✓",
                cellEditor: "agCheckboxCellEditor",
                checkboxSelection: true, // ✅ Abilita il checkbox nella prima colonna
                editable: true,
                sortable: true,
                width: 50,
            },
            { field: "id", headerName: "ID", width: 50 },
            {
                field: "name",
                headerName: "Name",
                cellRenderer: LangRenderer,
                width: 1600,
            },
        ]);
        const defaultColDef =
            ref <
            ColDef >
            {
                flex: 1,
                minWidth: 100,
            };
        const rowSelection = ref("multiple"); // ✅ Corretto

        const modules = ref([ClientSideRowModelModule]);

        const onGridReady = (params) => {
            agGrid.value = params.api;
            // Aspetta un attimo per assicurarti che le righe siano renderizzate
            params.api.forEachNode((node) => {
                if (selectedEcFeaturesIds.value.includes(node.data.id)) {
                    node.setSelected(true); // ✅ Seleziona la riga se è nei selezionati
                }
            });
        };

        const onSelectionChanged = (params) => {
            console.log("⚡ Evento onSelectionChanged chiamato!");

            if (!agGrid.value) {
                console.warn("⚠️ AG Grid non inizializzato correttamente.");
                return;
            }

            // Ottieni le righe selezionate attualmente
            const selectedNodes = params.api.getSelectedNodes();
            const newlySelectedIds = selectedNodes.map((node) => node.data.id);

            console.log("✅ Nuove selezioni:", newlySelectedIds);

            // Mantieni lo stato precedente e aggiorna solo le modifiche
            const updatedSelection = new Set([
                ...selectedEcFeaturesIds.value,
                ...newlySelectedIds,
            ]);

            // Aggiorna lo stato delle features
            ecFeatures.value = ecFeatures.value.map((feature) => ({
                ...feature,
                selected: updatedSelection.has(feature.id), // ✅ Mantiene selezioni precedenti
            }));

            // Aggiorna `selectedEcFeaturesIds` con le nuove selezioni
            selectedEcFeaturesIds.value = Array.from(updatedSelection);
        };

        return {
            agGrid,
            ecFeatures,
            colDefs,
            defaultColDef,
            modules,
            onGridReady,
            onSelectionChanged,
            selectedEcFeaturesIds,
            rowSelection,
        };
    },
});
</script>

<style scoped>
.ag-theme-alpine {
    width: 100%;
    height: 500px;
}
</style>
