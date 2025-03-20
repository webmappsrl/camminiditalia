<template>
    <portal to="modals">
        <transition name="fade">
            <div class="fixed inset-0 z-50">
                <div class="fixed inset-0 bg-80 opacity-50"></div>
                <div
                    class="relative h-full overflow-hidden p-4 flex items-center justify-center"
                >
                    <div
                        class="bg-white rounded-lg shadow-lg overflow-hidden w-full max-w-lg"
                    >
                        <div class="p-4 border-b border-40 bg-40">
                            <h3 class="text-90 font-normal text-xl">
                                Conferma cambio modalità
                            </h3>
                        </div>
                        <div class="p-4">
                            <p class="text-80 leading-normal">
                                Passando alla modalità automatica perderai tutte
                                le selezioni manuali. Sei sicuro di voler
                                continuare?
                            </p>
                        </div>
                        <div
                            class="bg-30 px-6 py-3 flex items-center justify-end"
                        >
                            <button
                                class="btn btn-link dim cursor-pointer text-80 font-normal text-base ml-auto mr-6"
                                @click="$emit('close')"
                            >
                                Annulla
                            </button>
                            <button
                                class="btn btn-default btn-danger"
                                @click="$emit('confirm')"
                            >
                                Conferma
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </transition>
    </portal>
</template>

<script lang="ts">
import { defineComponent } from "vue";

export default defineComponent({
    name: "ConfirmModal",
    emits: ["confirm", "close"],
});
</script>
