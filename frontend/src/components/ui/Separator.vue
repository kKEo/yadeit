<script setup lang="ts">
import { Separator as SeparatorRoot } from 'radix-vue'
import { cn } from '@/lib/utils'
interface Props { class?: string; orientation?: 'horizontal' | 'vertical'; decorative?: boolean }
const props = withDefaults(defineProps<Props>(), { orientation: 'horizontal', decorative: true })
</script>
<template>
  <SeparatorRoot
    :orientation="orientation" :decorative="decorative"
    :class="cn('shrink-0 bg-border', orientation === 'horizontal' ? 'h-[1px] w-full' : 'h-full w-[1px]', props.class)"
  />
</template>
