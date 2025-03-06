<template>
  <ul>
    <li v-for="track in ecTracks">
      {{track.name}}
    </li>
  </ul>
</template>

<script>
import { FormField, HandlesValidationErrors } from 'laravel-nova'
import { toRaw } from 'vue';

export default {
  mixins: [FormField, HandlesValidationErrors],

  props: ['resourceName', 'resourceId', 'field', 'value'],

  created() {
    console.log('__created', this.field?.tracks);
  },

  computed: {
    ecTracks() {
      console.log('__ecTracks', toRaw(this.field?.tracks));
      return toRaw(this.field?.tracks) || [];
    }
  },

  methods: {
    /*
     * Set the initial, internal value for the field.
     */
    setInitialValue() {
      console.log('__setInitialValue', this.field?.tracks);
      this.value = this.field.value || ''
    },

    /**
     * Fill the given FormData object with the field's internal value.
     */
    fill(formData) {
      formData.append(this.fieldAttribute, this.value || '')
    },
  },
}
</script>
