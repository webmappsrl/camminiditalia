<template>
    <div>
      <pre>{{ formattedJson }}</pre>
    </div>
  </template>
  
  <script>
  import { defineComponent, computed } from "vue";
  
  export default defineComponent({
    props: ["params"],
    setup(props) {
      const formattedJson = computed(() => {
        const values = Object.values(props.params.value);
        return values[0]; // Formatta con indentazione
      });
  
      return {
        formattedJson
      };
    }
  });
  </script>
  