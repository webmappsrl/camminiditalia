<template>
  <PanelItem :index="index" :field="field" />
  <div>Ciao detail</div>
</template>

<script>
export default {
  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
}
</script>
