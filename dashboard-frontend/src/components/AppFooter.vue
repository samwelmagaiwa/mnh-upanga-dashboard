<script setup>
import { computed } from 'vue'

const today = computed(() => {
  const date = new Date()
  const day = String(date.getDate()).padStart(2, '0')
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const year = date.getFullYear()
  return `${day}/${month}/${year}`
})
</script>

<template>
  <CFooter class="px-4">
    <div>
      <a href="#" target="_blank"></a>
      <span class="ms-1"
        >&copy; {{ new Date().getFullYear() }} MNH Upanga. Today's Date: {{ today }}</span
      >
    </div>
    <div class="ms-auto">
      <span class="me-1" target="_blank">Powered by</span>
      <a href="#">MNH Upanga ICT.</a>
    </div>
  </CFooter>
</template>
