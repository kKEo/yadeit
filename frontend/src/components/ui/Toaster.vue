<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { CircleAlert, CircleCheck, Info, X } from '@lucide/vue'
import { useToastStore, type ToastKind } from '@/stores/toast'

const store = useToastStore()
const { t } = useI18n()

const icons = { info: Info, success: CircleCheck, error: CircleAlert }
const tone: Record<ToastKind, string> = {
  info: 'border-border',
  success: 'border-green-500/50',
  error: 'border-destructive/60',
}
</script>

<template>
  <div
    class="pointer-events-none fixed bottom-4 right-4 z-[100] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2"
    role="region" :aria-label="t('toast.region')" aria-live="polite"
  >
    <div
      v-for="toast in store.toasts" :key="toast.id"
      :role="toast.kind === 'error' ? 'alert' : 'status'"
      class="pointer-events-auto flex items-start gap-3 rounded-md border bg-popover p-3 text-sm text-popover-foreground shadow-lg"
      :class="tone[toast.kind]"
    >
      <component
        :is="icons[toast.kind]" class="mt-0.5 h-4 w-4 shrink-0"
        :class="toast.kind === 'error' ? 'text-destructive' : toast.kind === 'success' ? 'text-green-600' : 'text-muted-foreground'"
      />
      <p class="min-w-0 flex-1 break-words">{{ toast.message }}</p>
      <button
        type="button" :aria-label="t('toast.dismiss')"
        class="rounded-sm opacity-70 hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        @click="store.dismiss(toast.id)"
      ><X class="h-4 w-4" /></button>
    </div>
  </div>
</template>
