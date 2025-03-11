<template>
    <PanelItem :index="index" :field="field" />
    <LayerFeature :field="field" />
</template>

<script>
import LayerFeature from "./LayerFeature.vue"; // 👈 Importa il componente
export default {
    components: {
        LayerFeature, // 👈 Dichiaralo nei componenti disponibili
    },
    props: ["index", "resource", "resourceName", "resourceId", "field"],
};
</script>
