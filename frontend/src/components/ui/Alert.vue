<script setup lang="ts">
import { cn } from '@/lib/utils'
import { cva, type VariantProps } from 'class-variance-authority'
import { computed } from 'vue'
import { AlertCircle, Info, CheckCircle2, AlertTriangle } from '@lucide/vue'

const alertVariants = cva(
  'relative w-full rounded-lg border p-4 [&>svg~*]:pl-7 [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:text-foreground',
  {
    variants: {
      variant: {
        default:     'bg-background text-foreground',
        destructive: 'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive',
        success:     'border-green-500/50 text-green-700 bg-green-50 [&>svg]:text-green-600',
        warning:     'border-yellow-500/50 text-yellow-700 bg-yellow-50 [&>svg]:text-yellow-600',
      },
    },
    defaultVariants: { variant: 'default' },
  },
)

type AlertVariants = VariantProps<typeof alertVariants>
interface Props { variant?: AlertVariants['variant']; title?: string; class?: string }
const props = withDefaults(defineProps<Props>(), { variant: 'default' })
const cls = computed(() => cn(alertVariants({ variant: props.variant }), props.class))

const IconComponent = computed(() => {
  switch (props.variant) {
    case 'destructive': return AlertCircle
    case 'success':     return CheckCircle2
    case 'warning':     return AlertTriangle
    default:            return Info
  }
})
</script>

<template>
  <div role="alert" :class="cls">
    <component :is="IconComponent" class="h-4 w-4" />
    <div>
      <p v-if="title" class="mb-1 font-medium leading-none tracking-tight">{{ title }}</p>
      <div class="text-sm [&_p]:leading-relaxed"><slot /></div>
    </div>
  </div>
</template>
