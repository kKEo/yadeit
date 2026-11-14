<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { shortcuts, type ShortcutInfo } from '@/composables/useShortcuts'
import { useUiStore } from '@/stores/ui'
import Dialog from '@/components/ui/Dialog.vue'
import Kbd from '@/components/ui/Kbd.vue'

/** "?": every shortcut registered right now, straight from the registry. */
const { t } = useI18n()
const ui = useUiStore()

const groups = computed(() => {
  // Read when the dialog opens: bindings come and go with the pages that own them.
  const bindings = ui.shortcutsHelpOpen ? shortcuts.list() : []
  const byGroup = new Map<string, ShortcutInfo[]>()
  for (const binding of bindings) byGroup.set(binding.group, [...(byGroup.get(binding.group) ?? []), binding])
  return [...byGroup.entries()].map(([group, items]) => ({ group, items }))
})
</script>

<template>
  <Dialog v-model:open="ui.shortcutsHelpOpen" :title="t('shortcuts.title')" :description="t('shortcuts.description')" :close-label="t('common.close')" class="max-h-[85vh] max-w-2xl overflow-y-auto">
    <div class="grid gap-6 sm:grid-cols-2">
      <section v-for="section in groups" :key="section.group">
        <h3 class="mb-2 text-sm font-medium">{{ t(section.group) }}</h3>
        <dl class="space-y-1.5">
          <div v-for="binding in section.items" :key="binding.keys" class="flex items-center justify-between gap-3 text-sm">
            <dt class="text-muted-foreground">{{ t(binding.description) }}</dt>
            <dd><Kbd :keys="binding.keys" /><span class="sr-only">{{ binding.keys }}</span></dd>
          </div>
        </dl>
      </section>
    </div>
  </Dialog>
</template>
