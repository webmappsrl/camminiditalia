<template>
    <span>{{ selectedCount }}</span>
</template>

<script>
export default {
    props: ["resourceName", "field"],

    computed: {
        selectedCount() {
            return this.field?.selectedEcFeaturesIds?.length || 0;
        },
    },
};
</script>
