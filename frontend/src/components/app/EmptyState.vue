<script setup lang="ts">
import type { Component } from 'vue'
import Button from '@/components/ui/Button.vue'
import Kbd from '@/components/ui/Kbd.vue'

/** A friendly dead end: what is missing, and the one thing to do about it. */
interface Props { icon?: Component; title: string; description?: string; actionLabel?: string; shortcut?: string }
defineProps<Props>()
const emit = defineEmits<{ action: [] }>()
</script>

<template>
  <div class="rounded-lg border border-dashed px-6 py-12 text-center">
    <component :is="icon" v-if="icon" class="mx-auto h-8 w-8 text-muted-foreground" />
    <h2 class="mt-3 font-medium">{{ title }}</h2>
    <p v-if="description" class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">{{ description }}</p>
    <div v-if="actionLabel" class="mt-4 flex items-center justify-center gap-2">
      <Button size="sm" @click="emit('action')">{{ actionLabel }}</Button>
      <Kbd v-if="shortcut" :keys="shortcut" />
    </div>
  </div>
</template>
