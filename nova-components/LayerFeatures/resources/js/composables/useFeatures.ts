import { ref, Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';
import type { GridData, LayerFeatureProps } from '../types/interfaces';

interface FilterModel {
    name?: {
        filter: string;
    };
}

interface Resource {
    id: {
        value: number;
    };
    fields: Array<{
        attribute: string;
        value: string;
    }>;
}

interface ApiResponse {
    resources: Resource[];
}


export function useFeatures(props: LayerFeatureProps) {
    const isLoading = ref<boolean>(true);
    const gridData = ref<GridData[]>([]);
    const persistentSelectedIds = ref<number[]>([]);
    const isSaving = ref<boolean>(false);
    const gridApi = ref<GridApi | null>(null);

    const updateSelectedNodes = () => {
        setTimeout(() => {
            console.log('[Selection] updateSelectedNodes called - Current persistentSelectedIds:', persistentSelectedIds.value);
            if (!gridApi.value) return;
            
            gridApi.value.forEachNode(node => {
                const isSelected = persistentSelectedIds.value.includes(node.data.id);
                if (node.data.isSelected !== isSelected) {
                    const updatedData = {
                        ...node.data,
                        isSelected: isSelected
                    };
                    node.setData(updatedData);
                }
            });

            // Forziamo il refresh delle celle checkbox
            gridApi.value.refreshCells({
                columns: ['boolean'],
                force: true
            });
        }, 100);
    };

    const setGridApi = (api: GridApi) => {
        gridApi.value = api;
    };

    const buildFilterObject = (modelName: string | undefined, layerId: number | undefined): Array<Record<string, any>> => {
        if (!modelName || !layerId) {
            throw new Error('ModelName and LayerId are required');
        }
        return [{ [`features_by_layer_${modelName}`]: layerId }];
    };

    const buildApiUrl = (filterObject: Array<Record<string, any>>, searchValue = ''): string => {
        const base64Filter = btoa(JSON.stringify(filterObject));
        let url = `/nova-api/ec-tracks?filters=${encodeURIComponent(base64Filter)}&perPage=100&trashed=&page=1&relationType=`;
        if (searchValue) {
            url += `&search=${encodeURIComponent(searchValue)}`;
        }
        return url;
    };

    const fetchTracks = async (url: string): Promise<ApiResponse> => {
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return await response.json();
    };

    const mapResourceToGridData = (resource: Resource): GridData => {
        const name = resource.fields.find(f => f.attribute === 'name')?.value || '';
        const id = resource.id.value;
        const isSelected = persistentSelectedIds.value.includes(id);
        return { id, name, boolean: isSelected };
    };

    const fetchFeatures = async (filterModel: FilterModel | null = null): Promise<void> => {
        try {
            isLoading.value = true;
            
            if (!props.field?.modelName || !props.field?.layerId) {
                throw new Error('Required field properties are missing');
            }

            const modelName = props.field.modelName;
            const layerId = props.field.layerId;
            const searchValue = filterModel?.name?.filter || '';

            // Prima chiamata: record selezionati (include)
            const includeFilters = [
                { [`features_by_layer_${modelName}`]: layerId },
                { id: { in: persistentSelectedIds.value } }
            ];
            const baseIncludeUrl = `/nova-api/ec-tracks?filters=${encodeURIComponent(btoa(JSON.stringify(includeFilters)))}&perPage=100&trashed=&page=1&relationType=`;
            const includeUrl = searchValue ? `${baseIncludeUrl}&search=${encodeURIComponent(searchValue)}` : baseIncludeUrl;
            const includeResponse = await fetch(includeUrl);
            const includeData = await includeResponse.json();
            const selectedRows = includeData.resources.map((resource: Resource) => {
                const name = resource.fields.find((f: { attribute: string }) => f.attribute === 'name')?.value || '';
                return { id: resource.id.value, name, isSelected: true };
            });

            // Seconda chiamata: record non selezionati (exclude)
            const excludeFilters = [
                { [`features_by_layer_${modelName}`]: layerId },
                { id: { notIn: persistentSelectedIds.value } }
            ];
            const baseExcludeUrl = `/nova-api/ec-tracks?filters=${encodeURIComponent(btoa(JSON.stringify(excludeFilters)))}&perPage=100&trashed=&page=1&relationType=`;
            const excludeUrl = searchValue ? `${baseExcludeUrl}&search=${encodeURIComponent(searchValue)}` : baseExcludeUrl;
            const excludeResponse = await fetch(excludeUrl);
            const excludeData = await excludeResponse.json();
            const unselectedRows = excludeData.resources.map((resource: Resource) => {
                const name = resource.fields.find((f: { attribute: string }) => f.attribute === 'name')?.value || '';
                return { id: resource.id.value, name, isSelected: false };
            });

            // Combiniamo i risultati: prima i selezionati, poi i non selezionati
            const selectedIds = new Set(selectedRows.map((row: GridData) => row.id));
            const filteredUnselectedRows = unselectedRows.filter((row: GridData) => !selectedIds.has(row.id));
            gridData.value = [...selectedRows, ...filteredUnselectedRows];
            
        } catch (error) {
            gridData.value = [];
            Nova.error('Errore durante il caricamento delle features');
            throw error;
        } finally {
            isLoading.value = false;
            updateSelectedNodes();
        }
    };

    const handleSave = async (): Promise<void> => {
        try {
            isSaving.value = true;
            const layerId = props.field.layerId;

            if (!layerId) {
                throw new Error('LayerId is required for saving');
            }

            await Nova.request().post(`/nova-vendor/layer-features/sync/${layerId}`, {
                features: persistentSelectedIds.value,
                model: props.field.model,
            });

            Nova.success('Features salvate con successo');
            props.field.value = persistentSelectedIds.value;
            props.field.selectedEcFeaturesIds = persistentSelectedIds.value;

            await fetchFeatures();
        } catch (error) {
            Nova.error('Errore durante il salvataggio delle features');
            throw error;
        } finally {
            isSaving.value = false;
        }
    };

    const addToPersistentSelection = (id: number) => {
        if (!persistentSelectedIds.value.includes(id)) {
            persistentSelectedIds.value = [...persistentSelectedIds.value, id];
            console.log('[Selection] Added ID to persistentSelectedIds:', id, '- Current array:', persistentSelectedIds.value);
        }
    };

    const removeFromPersistentSelection = (id: number) => {
        persistentSelectedIds.value = persistentSelectedIds.value.filter(selectedId => selectedId !== id);
        console.log('[Selection] Removed ID from persistentSelectedIds:', id, '- Current array:', persistentSelectedIds.value);
    };

    return {
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
    };
} 