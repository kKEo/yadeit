<script setup lang="ts">
/**
 * One property in the item header: a compact button that opens its picker.
 * An unset property is `empty`: icon only, so it costs almost no width.
 */
defineProps<{ label: string; value?: string | null; empty?: boolean; disabled?: boolean }>()
</script>

<template>
  <button
    type="button" :disabled="disabled" :title="label" :aria-label="value ? `${label}: ${value}` : label" :data-empty="empty ? 'true' : undefined"
    class="inline-flex h-7 min-w-0 max-w-[14rem] shrink-0 items-center gap-1.5 rounded-md border px-2 text-xs transition-colors
           hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none
           data-[empty=true]:border-dashed data-[empty=true]:px-1.5 data-[empty=true]:text-muted-foreground data-[state=open]:bg-accent"
  >
    <slot />
  </button>
</template>
