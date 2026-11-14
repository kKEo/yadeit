<script setup lang="ts">
import { cn } from '@/lib/utils'
interface Props { class?: string }
const props = defineProps<Props>()
</script>
<template>
  <div :class="cn('h-4 rounded-md bg-muted animate-pulse', props.class)" />
</template>
