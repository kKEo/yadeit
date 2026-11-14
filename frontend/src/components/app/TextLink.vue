<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router'

defineProps<{ to: RouteLocationRaw }>()
</script>

<template>
  <RouterLink :to="to" class="text-sm font-medium text-primary underline-offset-4 hover:underline">
    <slot />
  </RouterLink>
</template>
