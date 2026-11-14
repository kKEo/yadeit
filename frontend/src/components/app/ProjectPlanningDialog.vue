<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQueryCache } from '@pinia/colada'
import * as api from '@/api/projects'
import type { Cycle, Project } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { formatDate } from '@/lib/format'
import { keys, useSlug } from '@/queries/keys'
import FormField from '@/components/app/FormField.vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Dialog from '@/components/ui/Dialog.vue'
import Progress from '@/components/ui/Progress.vue'
import Select from '@/components/ui/Select.vue'

/** A project's planning settings (cycle length, stale-item decay) and its cycles so far. */
const props = defineProps<{ project: Project }>()
const emit = defineEmits<{ close: [] }>()
const { t, locale } = useI18n()
const slug = useSlug()
const cache = useQueryCache()
const form = useApiForm(['cycle_weeks', 'stale_after_days'])

const weeks = ref(props.project.cycle_weeks === null ? 'off' : String(props.project.cycle_weeks))
const stale = ref(props.project.stale_after_days === null ? 'off' : String(props.project.stale_after_days))
const cycles = ref<Cycle[]>([])
const saved = ref(false)

const weekOptions = computed(() => [
  { value: 'off', label: t('planning.off') },
  ...[1, 2, 3, 4, 6, 8].map((n) => ({ value: String(n), label: t('planning.weeks', n) })),
])
const staleOptions = computed(() => {
  const days = [14, 30, 60, 90]
  const current = props.project.stale_after_days
  if (current !== null && !days.includes(current)) days.push(current)
  return [
    { value: 'off', label: t('planning.off') },
    ...days.sort((a, b) => a - b).map((n) => ({ value: String(n), label: t('planning.days', { n }) })),
  ]
})

async function loadCycles() {
  cycles.value = await api.listCycles(slug.value, props.project.key)
}

onMounted(() => form.submit(loadCycles))

async function save() {
  saved.value = false
  const ok = await form.submit(async () => {
    await api.updateProject(slug.value, props.project.key, {
      cycle_weeks: weeks.value === 'off' ? null : Number(weeks.value),
      stale_after_days: stale.value === 'off' ? null : Number(stale.value),
    })
    await loadCycles()
    // The project list, the project's detail and item rows all show cycle information.
    void cache.invalidateQueries({ key: keys.projects(slug.value) })
    void cache.invalidateQueries({ key: keys.itemLists(slug.value) })
  })
  saved.value = ok
}

function percent(cycle: Cycle): number {
  return cycle.items.total === 0 ? 0 : Math.round((cycle.items.completed / cycle.items.total) * 100)
}
</script>

<template>
  <Dialog
    :open="true" :title="t('planning.title', { project: project.name })" :description="t('planning.description')"
    :close-label="t('common.close')" @update:open="!$event && emit('close')"
  >
    <form novalidate class="space-y-4" @submit.prevent="save">
      <Alert v-if="form.error.value" variant="destructive" :title="form.error.value" />
      <Alert v-else-if="saved" variant="success" :title="t('planning.saved')" />

      <FormField id="planning-cycles" :label="t('planning.cycles')" :hint="t('planning.cyclesHint')" :errors="form.fieldErrors.value.cycle_weeks">
        <Select id="planning-cycles" :model-value="weeks" :options="weekOptions" @update:model-value="weeks = $event" />
      </FormField>
      <FormField id="planning-stale" :label="t('planning.stale')" :hint="t('planning.staleHint')" :errors="form.fieldErrors.value.stale_after_days">
        <Select id="planning-stale" :model-value="stale" :options="staleOptions" @update:model-value="stale = $event" />
      </FormField>

      <div class="flex justify-end">
        <Button type="submit" :disabled="form.pending.value">{{ form.pending.value ? t('planning.saving') : t('planning.save') }}</Button>
      </div>
    </form>

    <section class="space-y-2 border-t pt-4">
      <h3 class="text-sm font-medium">{{ t('planning.history') }}</h3>
      <p v-if="cycles.length === 0" class="text-sm text-muted-foreground">{{ t('planning.noCycles') }}</p>
      <ol v-else class="max-h-56 space-y-3 overflow-y-auto">
        <li v-for="cycle in cycles" :key="cycle.id" class="space-y-1 text-sm" :data-cycle="cycle.number">
          <p class="flex flex-wrap items-center justify-between gap-2">
            <span class="flex items-center gap-2 font-medium">
              {{ cycle.name }}
              <Badge :variant="cycle.status === 'active' ? 'default' : 'outline'">{{ t(`planning.status.${cycle.status}`) }}</Badge>
            </span>
            <span class="text-xs text-muted-foreground">{{ formatDate(cycle.starts_on, locale) }} – {{ formatDate(cycle.ends_on, locale) }}</span>
          </p>
          <Progress :model-value="percent(cycle)" class="h-1.5" />
          <p class="text-xs text-muted-foreground">{{ t('planning.progress', { done: cycle.items.completed, total: cycle.items.total }) }}</p>
        </li>
      </ol>
    </section>
  </Dialog>
</template>
