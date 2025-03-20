import { ref, Ref } from 'vue';
import type { GridApi, ColDef, GridOptions } from 'ag-grid-community';
import type { GridState } from '../types/interfaces';

interface ColumnDefinition extends ColDef {
    field: string;
    headerName: string;
    width?: number;
    flex?: number;
    minWidth?: number;
    checkboxSelection?: boolean;
    headerCheckboxSelection?: boolean;
    suppressSizeToFit?: boolean;
    filter?: boolean | string;
}

export function useGrid() {
    const gridApi = ref<GridApi | null>(null);
    const gridState = ref<GridState>({
        columnState: null,
        filterState: null,
        sortState: null,
    });

    const columnDefs = ref<ColumnDefinition[]>([
        {
            field: 'boolean',
            headerName: '✓',
            width: 50,
            checkboxSelection: true,
            headerCheckboxSelection: true,
            suppressSizeToFit: true,
            filter: false,
        },
        {
            field: 'id',
            headerName: 'ID',
            width: 80,
            suppressSizeToFit: true,
            filter: false,
        },
        {
            field: 'name',
            headerName: 'Name',
            flex: 1,
            minWidth: 200,
            filter: 'NameFilter',
        },
    ]);

    const defaultColDef = ref<Partial<ColDef>>({
        sortable: true,
        resizable: true,
        suppressMenu: true,
        suppressRowClickSelection: true,
        filter: false,
        floatingFilter: true,
    });

    const onGridReady = (params: { api: GridApi }): void => {
        gridApi.value = params.api;
        if (gridApi.value) {
            gridApi.value.sizeColumnsToFit();
        }
    };

    const onSelectionChanged = (callback: (selectedIds: number[]) => void) => {
        return () => {
            if (!gridApi.value) return;

            const selectedNodes = gridApi.value.getSelectedNodes();
            const selectedIds = selectedNodes.map(node => node.data.id);
            callback(selectedIds);
        };
    };

    const restoreSelections = (selectedIds: number[]): void => {
        if (!gridApi.value) return;

        gridApi.value.deselectAll();
        gridApi.value.forEachNode((node) => {
            if (selectedIds.includes(node.data.id)) {
                node.setSelected(true);
            }
        });
    };

    return {
        gridApi,
        gridState,
        columnDefs,
        defaultColDef,
        onGridReady,
        onSelectionChanged,
        restoreSelections,
    };
} 