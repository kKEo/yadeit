<script setup lang="ts">
import { RouterView } from 'vue-router'
import { useEventListener } from '@vueuse/core'
import { shortcuts } from '@/composables/useShortcuts'
import Toaster from '@/components/ui/Toaster.vue'

// One listener feeds every registered shortcut; pages add and remove bindings as they mount.
useEventListener(window, 'keydown', (event: KeyboardEvent) => shortcuts.handle(event))
</script>

<template>
  <RouterView />
  <Toaster />
</template>
