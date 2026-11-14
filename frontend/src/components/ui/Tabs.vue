<script setup lang="ts">
import { TabsRoot, TabsList, TabsTrigger, TabsContent } from 'radix-vue'
import { cn } from '@/lib/utils'

interface Tab { value: string; label: string }
interface Props { modelValue?: string; tabs: Tab[]; class?: string }
const props = defineProps<Props>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <TabsRoot :model-value="modelValue" @update:model-value="emit('update:modelValue', $event as string)">
    <TabsList :class="cn('inline-flex h-10 items-center justify-center rounded-md bg-muted p-1 text-muted-foreground', props.class)">
      <TabsTrigger
        v-for="tab in tabs" :key="tab.value" :value="tab.value"
        class="inline-flex items-center justify-center whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-background
               transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2
               disabled:pointer-events-none disabled:opacity-50
               data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm"
      >{{ tab.label }}</TabsTrigger>
    </TabsList>
    <TabsContent
      v-for="tab in tabs" :key="tab.value" :value="tab.value"
      class="mt-2 ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    ><slot :name="tab.value" /></TabsContent>
  </TabsRoot>
</template>
