import { ref, Ref } from 'vue';
import type { GridApi, ColDef, GridOptions, RowNode, ColumnApi } from 'ag-grid-community';
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
    cellRenderer?: (params: any) => string;
    onCellClicked?: (params: any) => void;
    sortable?: boolean;
}

export function useGrid() {
    const gridApi = ref<GridApi | null>(null);
    const gridState = ref<GridState>({
        columnState: null,
        filterState: null,
        sortState: null,
    });

    // Funzione per ordinare manualmente i dati
    const sortBySelection = (api: any): void => {
        console.log('[Sort] Inizio ordinamento manuale');
        
        // Raccogliamo tutti i dati
        const allData: any[] = [];
        api.forEachNode((node: any) => {
            allData.push(node.data);
        });
        console.log('[Sort] Dati raccolti:', allData.length, 'righe');

        // Ordiniamo i dati
        allData.sort((a, b) => {
            if (a.isSelected === b.isSelected) {
                // Se entrambi sono selezionati o deselezionati, mantieni l'ordine per ID
                return a.id - b.id;
            }
            // Metti i selezionati in cima
            return a.isSelected ? -1 : 1;
        });
        console.log('[Sort] Dati ordinati');

        // Aggiorniamo la griglia
        api.setRowData(allData);
        console.log('[Sort] Griglia aggiornata con i dati ordinati');
    };

    const columnDefs = ref<ColumnDefinition[]>([
        {
            field: 'boolean',
            headerName: '✓',
            width: 50,
            sortable: true,
            cellRenderer: (params: { data: any; api: GridApi; node: any; context: any; event: any }) => {
                const checked = params.data.isSelected ? 'checked' : '';
                return `
                    <input type="checkbox" class="ag-checkbox-input" ${checked} data-id="${params.data.id}" />
                `;
            },
            onCellClicked: (params: { data: any; api: GridApi; node: any; context: any; event: any }) => {
                const checkbox = params.event.target;
                if (checkbox.tagName === 'INPUT' && checkbox.type === 'checkbox') {
                    const id = parseInt(checkbox.dataset.id);
                    const isSelected = checkbox.checked;
                    const name = params.node.data.name;
                    
                    console.log(`[Checkbox] ID: ${id} - Nome: ${name} - ${isSelected ? 'Selezionato' : 'Deselezionato'}`);
                    
                    // Aggiorniamo lo stato della riga
                    const updatedData = {
                        ...params.node.data,
                        isSelected: isSelected
                    };
                    params.node.setData(updatedData);

                    // Aggiorniamo persistentSelectedIds attraverso l'evento
                    if (isSelected) {
                        if (typeof params.context.addToPersistentSelection === 'function') {
                            console.log(`[Selection] Aggiungo ID ${id} a persistentSelectedIds`);
                            params.context.addToPersistentSelection(id);
                        } else {
                            console.error('[Error] addToPersistentSelection non è una funzione');
                        }
                    } else {
                        if (typeof params.context.removeFromPersistentSelection === 'function') {
                            console.log(`[Selection] Rimuovo ID ${id} da persistentSelectedIds`);
                            params.context.removeFromPersistentSelection(id);
                        } else {
                            console.error('[Error] removeFromPersistentSelection non è una funzione');
                        }
                    }
                    
                    // Forziamo il refresh della cella per aggiornare lo stile
                    params.api.refreshCells({
                        rowNodes: [params.node],
                        columns: ['boolean'],
                        force: true
                    });

                    // Applichiamo l'ordinamento manuale
                    sortBySelection(params.api);
                }
            }
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
        filter: false,
        floatingFilter: true,
    });

    const onGridReady = (params: { api: any }): void => {
        console.log('[Grid Ready] Inizializzazione della griglia');
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
        sortBySelection,
    };
} 