<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { v4 as uuidv4 } from 'uuid'
import { ListTree, Sparkles, X } from '@lucide/vue'
import * as ai from '@/api/ai'
import type { AiItemSummary, AiSubItem, ItemDetail } from '@/api/types'
import { useAi } from '@/composables/useAi'
import { useApiForm } from '@/composables/useApiForm'
import { useCreateItem } from '@/queries/itemMutations'
import { useSlug } from '@/queries/keys'
import AiNote from '@/components/app/AiNote.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Checkbox from '@/components/ui/Checkbox.vue'

/**
 * AI help on one item: a summary of where it stands, and proposed sub-items.
 * Both are shown for a person to read; sub-items are only created for the
 * ones that person ticks.
 */
const props = defineProps<{ item: ItemDetail }>()
const { t } = useI18n()
const slug = useSlug()
const { available } = useAi()
const form = useApiForm()
const createItem = useCreateItem()

const summary = ref<AiItemSummary | null>(null)
const proposals = ref<AiSubItem[] | null>(null)
const chosen = ref<number[]>([])
const creating = ref(false)

function summarise() {
  return form.submit(async () => { summary.value = await ai.summariseItem(slug.value, props.item.key) })
}

function split() {
  return form.submit(async () => {
    proposals.value = await ai.splitItem(slug.value, props.item.key)
    chosen.value = proposals.value.map((_, index) => index)
  })
}

function toggle(index: number, checked: boolean) {
  chosen.value = checked ? [...chosen.value, index].sort((a, b) => a - b) : chosen.value.filter((i) => i !== index)
}

async function createChosen() {
  if (!proposals.value) return
  creating.value = true
  const remaining: AiSubItem[] = []
  // One after another, in the proposed order; whatever fails stays on the list.
  for (const [index, proposal] of proposals.value.entries()) {
    if (!chosen.value.includes(index)) continue
    try {
      await createItem.mutateAsync({
        req: { project: props.item.project.key, title: proposal.title, description: proposal.description, parent: props.item.key },
        idempotencyKey: uuidv4(),
      })
    } catch {
      remaining.push(proposal)
    }
  }
  creating.value = false
  proposals.value = remaining.length ? remaining : null
  chosen.value = remaining.map((_, index) => index)
}
</script>

<template>
  <section v-if="available" class="space-y-3" data-item-ai>
    <div class="flex flex-wrap gap-2">
      <Button variant="outline" size="sm" class="h-8 gap-1.5" :disabled="form.pending.value" @click="summarise">
        <Sparkles class="h-3.5 w-3.5" />{{ t('ai.summary.button') }}
      </Button>
      <Button variant="outline" size="sm" class="h-8 gap-1.5" :disabled="form.pending.value" @click="split">
        <ListTree class="h-3.5 w-3.5" />{{ t('ai.split.button') }}
      </Button>
      <span v-if="form.pending.value" class="self-center text-xs text-muted-foreground" aria-live="polite">{{ t('ai.working') }}</span>
    </div>
    <Alert v-if="form.error.value" variant="destructive" :title="form.error.value" />

    <div v-if="summary" class="space-y-2 rounded-lg border bg-muted/30 p-3 text-sm" data-ai-summary>
      <div class="flex items-start justify-between gap-2">
        <AiNote />
        <button type="button" class="rounded p-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('ai.dismiss')" @click="summary = null"><X class="h-3.5 w-3.5" /></button>
      </div>
      <p>{{ summary.summary }}</p>
      <template v-if="summary.decisions.length">
        <h4 class="text-xs font-medium text-muted-foreground">{{ t('ai.summary.decisions') }}</h4>
        <ul class="list-disc space-y-0.5 pl-5"><li v-for="(line, i) in summary.decisions" :key="i">{{ line }}</li></ul>
      </template>
      <template v-if="summary.open_questions.length">
        <h4 class="text-xs font-medium text-muted-foreground">{{ t('ai.summary.open') }}</h4>
        <ul class="list-disc space-y-0.5 pl-5"><li v-for="(line, i) in summary.open_questions" :key="i">{{ line }}</li></ul>
      </template>
    </div>

    <div v-if="proposals" class="space-y-2 rounded-lg border bg-muted/30 p-3 text-sm" data-ai-split>
      <div class="flex items-start justify-between gap-2">
        <AiNote />
        <button type="button" class="rounded p-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" :aria-label="t('ai.dismiss')" @click="proposals = null"><X class="h-3.5 w-3.5" /></button>
      </div>
      <p v-if="proposals.length === 0" class="text-muted-foreground">{{ t('ai.split.none') }}</p>
      <template v-else>
        <ul class="space-y-2">
          <li v-for="(proposal, index) in proposals" :key="index" class="flex items-start gap-2">
            <Checkbox :id="`split-${item.key}-${index}`" class="mt-0.5" :model-value="chosen.includes(index)" @update:model-value="toggle(index, $event === true)" />
            <label :for="`split-${item.key}-${index}`" class="min-w-0">
              <span class="block font-medium">{{ proposal.title }}</span>
              <span class="block text-muted-foreground">{{ proposal.description }}</span>
            </label>
          </li>
        </ul>
        <Button size="sm" :disabled="chosen.length === 0 || creating" @click="createChosen">
          {{ creating ? t('ai.split.creating') : t('ai.split.create', chosen.length) }}
        </Button>
      </template>
    </div>
  </section>
</template>
