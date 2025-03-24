export interface LayerFeatureProps {
    resourceName: string;
    resourceId: number | string;
    field: {
        selectedEcFeaturesIds?: number[];
        model?: string;
        modelName?: string;
        layerId?: number;
        value?: any[];
    };
    edit?: boolean;
    value?: any[];
}

export interface GridData {
    id: number;
    name: string;
    boolean?: boolean;
    isSelected?: boolean;
}

export interface GridState {
    columnState: any;
    filterState: any;
    sortState: any;
}

export interface CustomHeaderProps {
    params: {
        displayName: string;
        save: () => Promise<void>;
    };
}

export interface NameFilterProps {
    params: {
        filterChangedCallback: () => void;
    };
}

export interface CustomStatsProps {
    params: {
        api: {
            handleSave: () => void;
        };
    };
} 