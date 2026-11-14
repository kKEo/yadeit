<script setup lang="ts">
import {
  DialogRoot, DialogTrigger, DialogPortal, DialogOverlay,
  DialogContent, DialogTitle, DialogDescription, DialogClose,
} from 'radix-vue'
import { cn } from '@/lib/utils'
import { X } from '@lucide/vue'

interface Props { open?: boolean; title: string; description?: string; closeLabel?: string; class?: string }
const props = withDefaults(defineProps<Props>(), { open: false, closeLabel: 'Close' })
const emit = defineEmits<{ 'update:open': [value: boolean] }>()
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogTrigger v-if="$slots.trigger" as-child><slot name="trigger" /></DialogTrigger>
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-50 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />
      <DialogContent
        :class="cn(
          'fixed left-[50%] top-[50%] z-50 grid w-full max-w-lg translate-x-[-50%] translate-y-[-50%]',
          'gap-4 border bg-background p-6 shadow-lg duration-200',
          'data-[state=open]:animate-in data-[state=closed]:animate-out',
          'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
          'data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
          'data-[state=closed]:slide-out-to-left-1/2 data-[state=closed]:slide-out-to-top-[48%]',
          'data-[state=open]:slide-in-from-left-1/2 data-[state=open]:slide-in-from-top-[48%]',
          'sm:rounded-lg', props.class,
        )"
        @interact-outside="emit('update:open', false)"
      >
        <div class="flex flex-col space-y-1.5 text-center sm:text-left">
          <DialogTitle class="text-lg font-semibold leading-none tracking-tight">{{ title }}</DialogTitle>
          <DialogDescription v-if="description" class="text-sm text-muted-foreground">{{ description }}</DialogDescription>
        </div>
        <slot />
        <div v-if="$slots.footer" class="flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2"><slot name="footer" /></div>
        <DialogClose
          class="absolute right-4 top-4 rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:pointer-events-none"
          @click="emit('update:open', false)"
        >
          <X class="h-4 w-4" /><span class="sr-only">{{ closeLabel }}</span>
        </DialogClose>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
