<template>
    <div class="toggle-switch">
        <button
            type="button"
            class="toggle-button"
            :class="{ 'toggle-button--active': isManual }"
            @click="$emit('toggle')"
        >
            <span class="toggle-slider"></span>
        </button>
        <span class="label-text ml-2">
            {{ isManual ? "Selezione Manuale" : "Selezione Automatica" }}
        </span>
    </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

export default defineComponent({
    name: "ToggleSwitch",
    props: {
        isManual: {
            type: Boolean,
            required: true,
        },
    },
    emits: ["toggle"],
});
</script>

<style scoped>
.toggle-switch {
    display: inline-flex;
    align-items: center;
    cursor: pointer;
}

.toggle-button {
    position: relative;
    width: 50px;
    height: 24px;
    background-color: #ccc;
    border-radius: 24px;
    border: none;
    padding: 0;
    cursor: pointer;
    transition: background-color 0.3s;
}

.toggle-button--active {
    background-color: #4099de;
}

.toggle-slider {
    position: absolute;
    top: 4px;
    left: 4px;
    width: 16px;
    height: 16px;
    background-color: white;
    border-radius: 50%;
    transition: transform 0.3s;
}

.toggle-button--active .toggle-slider {
    transform: translateX(26px);
}

.label-text {
    margin-left: 10px;
}
</style>
