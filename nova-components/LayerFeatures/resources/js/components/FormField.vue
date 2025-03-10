<template>
    <ag-grid-vue
        ref="agGrid"
        class="ag-theme-alpine"
        @grid-ready="onGridReady"
        :columnDefs="colDefs"
        :defaultColDef="defaultColDef"
        :rowSelection="rowSelection"
        :rowData="ecTracks"
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
    ColDef,
    ColGroupDef,
    GridApi,
    GridOptions,
    GridReadyEvent,
    ModuleRegistry,
    ValidationModule,
    RowSelectionModule,
    createGrid,
    RowSelectionOptions,
    TextEditorModule,
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
        const ecTracks = computed(() => toRaw(props.field?.tracks) || []);
        const selectedEcTrackIds = computed(
            () => toRaw(props.field?.selectedTracks) || [],
        );

        console.log(
            "Tracce selezionate inizialmente:",
            selectedEcTrackIds.value,
        );

        const colDefs = ref([
            {
                field: "boolean",
                cellRenderer: "agCheckboxCellRenderer",
                cellEditor: "agCheckboxCellEditor",
                editable: true,
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
        const rowSelection =
            (ref < RowSelectionOptions) |
            "single" |
            ("multiple" >
                {
                    mode: "multiRow",
                });
        const modules = ref([ClientSideRowModelModule]);

        // Seleziona le righe iniziali
        const onGridReady = (params) => {
            agGrid.value = params.api;
            const selectedEcTrackIds = [1];
            params.api.forEachNode((node) => {
                console.log("Node:", node.data.id);
                console.log(selectedEcTrackIds);
                if (selectedEcTrackIds.includes(node.data.id)) {
                    console.log("Seleziono:", node.data.id);
                    node.setSelected(true);
                }
            });
        };

        // Aggiorna lo stato dei checkbox selezionati quando cambia la selezione
        const onSelectionChanged = (params) => {
            console.log("Selezionati:", selectedEcTrackIds.value);
        };

        return {
            agGrid,
            ecTracks,
            colDefs,
            modules,
            onGridReady,
            onSelectionChanged,
            selectedEcTrackIds,
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
