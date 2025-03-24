<template>
    <LayerFeature :field="field" :edit="false" />
</template>

<script>
import LayerFeature from "./LayerFeature.vue";
export default {
    components: {
        LayerFeature,
    },
    props: ["index", "resource", "resourceName", "resourceId", "field", "edit"],
};
</script>
