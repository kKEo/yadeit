<script setup lang="ts">
import Label from '@/components/ui/Label.vue'

/** Label, control (default slot) and the first API error for that field. */
interface Props { id: string; label: string; errors?: string[]; hint?: string }
defineProps<Props>()
</script>

<template>
  <div class="space-y-1.5">
    <Label :for="id">{{ label }}</Label>
    <slot />
    <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
    <p v-if="errors?.length" :id="`${id}-error`" class="text-sm text-destructive">{{ errors[0] }}</p>
  </div>
</template>
