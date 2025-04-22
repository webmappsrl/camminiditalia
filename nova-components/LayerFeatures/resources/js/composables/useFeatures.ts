import { ref, Ref } from 'vue';
import { IRowNode } from 'ag-grid-community';
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
    title : string,
    fields: Array<{
        attribute: string;
        value: string;
    }>;
}

export function useFeatures(props: LayerFeatureProps) {
    const isLoading = ref<boolean>(true);
    const gridData = ref<GridData[]>([]);
    const persistentSelectedIds = ref<number[]>([]);
    const isSaving = ref<boolean>(false);
    const gridApi = ref<any | null>(null);

    const updateSelectedNodes = () => {
        setTimeout(() => {
            if (!gridApi.value) return;
            
            gridApi.value.forEachNode((node: IRowNode) => {
                const isSelected = persistentSelectedIds.value.includes(node.data.id);
                if (node.data.isSelected !== isSelected) {
                    const updatedData = {
                        ...node.data,
                        isSelected: isSelected
                    };
                    node.setData(updatedData);
                }
            });

            gridApi.value.refreshCells({
                columns: ['boolean'],
                force: true
            });
        }, 100);
    };

    const setGridApi = (api: any) => {
        gridApi.value = api;
    };

    const sortBySelection = (api: any | null): void => {
        if (!api) return;
        
        const allData: GridData[] = [];
        api.forEachNode((node: IRowNode) => {
            allData.push(node.data);
        });

        allData.sort((a, b) => {
            if (a.isSelected === b.isSelected) {
                return a.id - b.id;
            }
            return a.isSelected ? -1 : 1;
        });

        api.setRowData(allData);
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

            const includeFilters = [
                { [`features_by_layer_${modelName}`]: layerId },
                { "features_include_ids_ecTracks": persistentSelectedIds.value }
            ];
            const baseIncludeUrl = `/nova-api/ec-tracks?filters=${encodeURIComponent(btoa(JSON.stringify(includeFilters)))}&perPage=100&trashed=&page=1&relationType=`;
            const includeUrl = searchValue ? `${baseIncludeUrl}&search=${encodeURIComponent(searchValue)}` : baseIncludeUrl;
            const includeResponse = await fetch(includeUrl);
            const includeData = await includeResponse.json();
            const selectedRows = includeData.resources.map((resource: Resource) => {
                const name = resource.title;
                return { id: resource.id.value, name, isSelected: true };
            });

            if (props.edit) {
                const excludeFilters = [
                    { [`features_by_layer_${modelName}`]: layerId },
                    { "features_exclude_ids_ecTracks": persistentSelectedIds.value }
                ];
                const baseExcludeUrl = `/nova-api/ec-tracks?filters=${encodeURIComponent(btoa(JSON.stringify(excludeFilters)))}&perPage=100&trashed=&page=1&relationType=`;
                const excludeUrl = searchValue ? `${baseExcludeUrl}&search=${encodeURIComponent(searchValue)}` : baseExcludeUrl;
                const excludeResponse = await fetch(excludeUrl);
                const excludeData = await excludeResponse.json();
                const unselectedRows = excludeData.resources.map((resource: Resource) => {
                    const name = resource.title;
                    return { id: resource.id.value, name, isSelected: false };
                });

                const selectedIds = new Set(selectedRows.map((row: GridData) => row.id));
                const filteredUnselectedRows = unselectedRows.filter((row: GridData) => !selectedIds.has(row.id));
                gridData.value = [...selectedRows, ...filteredUnselectedRows];
            } else {
                gridData.value = selectedRows;
            }
            
        } catch (error) {
            gridData.value = [];
            Nova.error('Errore durante il caricamento delle features');
            throw error;
        } finally {
            isLoading.value = false;
            updateSelectedNodes();
            setTimeout(() => {
                sortBySelection(gridApi.value);
            }, 100);
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
        }
    };

    const removeFromPersistentSelection = (id: number) => {
        persistentSelectedIds.value = persistentSelectedIds.value.filter(selectedId => selectedId !== id);
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