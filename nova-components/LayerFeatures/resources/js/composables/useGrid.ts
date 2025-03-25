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
                    // Se siamo in modalità non-edit e la checkbox è checked, non permettiamo la deselection
                    if (!params.context.edit && params.node.data.isSelected) {
                        checkbox.checked = true;
                        return;
                    }

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
        {
            field: 'actions',
            headerName: '',
            width: 50,
            sortable: false,
            filter: false,
            cellRenderer: (params: { data: any }) => {
                return `
                    <div class="flex items-center justify-center h-full">
                        <a 
                            class="inline-flex items-center justify-center w-8 h-8 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors duration-150 ease-in-out" 
                            href="/nova/resources/ec-tracks/${params.data.id}"
                            target="_blank"
                            title="Visualizza"
                        >
                            <svg 
                                xmlns="http://www.w3.org/2000/svg" 
                                fill="none" 
                                viewBox="0 0 24 24" 
                                stroke-width="2" 
                                stroke="currentColor" 
                                class="w-5 h-5 text-gray-500 dark:text-gray-400 hover:text-primary-500 dark:hover:text-primary-500"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"></path>
                            </svg>
                        </a>
                    </div>
                `;
            }
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