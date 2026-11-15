<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  ArrowLeft, Ban, Bell, BellOff, Bot, CalendarDays, CornerLeftUp, Ellipsis, Link2, Maximize2, Megaphone, Minimize2, RefreshCw, SignalMedium, Tag, Trash2,
  User, X,
} from '@lucide/vue'
import type { ItemDetail } from '@/api/types'
import { useItemLink } from '@/composables/useItemLink'
import { useItemProperties } from '@/composables/useItemProperties'
import { formatDate, isOverdue } from '@/lib/format'
import { KIND_ICONS, PRIORITY_ICONS } from '@/lib/pickerOptions'
import { useSlug } from '@/queries/keys'
import { useUiStore, type ItemProperty } from '@/stores/ui'
import PropertyPicker from '@/components/app/PropertyPicker.vue'
import Button from '@/components/ui/Button.vue'
import DropdownMenu from '@/components/ui/DropdownMenu.vue'
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue'
import Kbd from '@/components/ui/Kbd.vue'
import ChipPopover from './ChipPopover.vue'
import NeedsInputBadge from './NeedsInputBadge.vue'
import PropertyChip from './PropertyChip.vue'
import UserInitials from './UserInitials.vue'

/**
 * The top of an item, the same in the side panel and on the full page: key and
 * actions, the title, and the properties as chips. It stays in place while the
 * content below scrolls. The full page keeps the less used properties in its
 * details column, so its header shows fewer chips.
 */
const props = defineProps<{ itemKey: string; item: ItemDetail | null; canEdit: boolean; variant: 'peek' | 'page' }>()
const emit = defineEmits<{ open: [key: string]; expand: []; collapse: []; close: []; delete: []; toggleSubscription: [] }>()
const { t, locale } = useI18n()
const ui = useUiStore()
const slug = useSlug()
const { copyLink } = useItemLink()
const {
  options, values, apply, update, agents, delegateOptions, delegateTo, cycleOptions, hasCycles, setCycle, setDueDate, setParent, blockers,
} = useItemProperties(() => props.item)

const title = ref('')
watch(() => props.item?.title, (value) => { title.value = value ?? '' }, { immediate: true })

function saveTitle() {
  const next = title.value.trim()
  if (!props.item || next === props.item.title) return
  if (next === '') { title.value = props.item.title; return }
  void update(props.item.key, { title: next })
}

/** Pickers this header shows; a request for another one is left to the details column. */
const pickers = computed<ItemProperty[]>(() => (
  props.variant === 'peek' ? ['state', 'priority', 'assignee', 'labels', 'kind'] : ['state', 'priority', 'assignee', 'kind']
))
const openPicker = ref<ItemProperty | null>(null)

// S / P / A / L (or the command palette) ask for a picker of the item on screen: open it in place.
watch([() => ui.propertyRequest, () => props.item?.key], ([request, key]) => {
  if (!request || request.key !== key || !props.canEdit || !pickers.value.includes(request.property)) return
  openPicker.value = request.property
  ui.propertyRequest = null
}, { immediate: true })

const picker = (property: ItemProperty) => ({
  open: openPicker.value === property,
  label: t(`items.fields.${property}`),
  options: options.value[property],
  modelValue: values.value[property],
  multiple: property === 'labels',
  disabled: !props.canEdit,
  'onUpdate:open': (value: boolean) => { openPicker.value = value ? property : null },
  'onUpdate:modelValue': (value: string | string[]) => apply(property, value),
})

const delegateOpen = ref(false)
const cycleOpen = ref(false)
const dueOpen = ref(false)
const parentOpen = ref(false)

function pickDueDate(value: string | null) {
  setDueDate(value)
  dueOpen.value = false
}

const parentDraft = ref('')
watch(() => [props.item?.parent, parentOpen.value], () => { parentDraft.value = props.item?.parent ?? '' }, { immediate: true })

async function saveParent() {
  const saved = await setParent(parentDraft.value)
  if (saved || parentDraft.value.trim().toUpperCase() === (props.item?.parent ?? '')) parentOpen.value = false
}

function openParent() {
  parentOpen.value = false
  if (props.item?.parent) emit('open', props.item.parent)
}

const overdue = computed(() => !!props.item?.due_date && !props.item.completed_at && isOverdue(props.item.due_date))
const subscribeLabel = computed(() => (props.item?.subscribed ? t('items.detail.unsubscribe') : t('items.detail.subscribe')))
const inputClass = 'h-8 w-full rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
</script>

<template>
  <header
    data-item-header
    :class="variant === 'page' ? 'sticky top-[var(--app-header-height,3.5rem)] z-20 -mx-4 border-b bg-background px-4 pb-3 pt-2' : 'shrink-0 border-b px-4 pb-3 pt-2'"
  >
    <div class="flex items-center justify-between gap-2">
      <div class="flex min-w-0 items-center gap-1.5 text-sm">
        <template v-if="variant === 'page'">
          <RouterLink
            :to="{ name: 'items', params: { slug } }"
            class="inline-flex shrink-0 items-center gap-1.5 rounded text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          ><ArrowLeft class="h-4 w-4" />{{ t('items.title') }}</RouterLink>
          <span class="text-muted-foreground/50" aria-hidden="true">/</span>
          <template v-if="item">
            <span class="hidden truncate text-muted-foreground md:inline">{{ item.project.name }}</span>
            <span class="hidden text-muted-foreground/50 md:inline" aria-hidden="true">/</span>
          </template>
        </template>

        <PropertyPicker v-if="item" v-bind="picker('kind')">
          <template #trigger="{ summary }">
            <button
              type="button" :disabled="!canEdit" :title="`${t('items.fields.kind')}: ${summary}`" :aria-label="`${t('items.fields.kind')}: ${summary}`"
              class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent
                     focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none data-[state=open]:bg-accent"
            ><component :is="KIND_ICONS[item.kind]" class="h-4 w-4" /></button>
          </template>
        </PropertyPicker>
        <span class="shrink-0 font-mono text-sm text-muted-foreground">{{ itemKey }}</span>

        <template v-if="item">
          <ChipPopover v-if="canEdit" v-model:open="parentOpen" :label="t('items.fields.parent')">
            <template #trigger>
              <PropertyChip :label="t('items.fields.parent')" :value="item.parent" :empty="!item.parent" class="h-6 font-mono">
                <CornerLeftUp class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" /><span v-if="item.parent">{{ item.parent }}</span>
              </PropertyChip>
            </template>
            <form class="space-y-2" @submit.prevent="saveParent">
              <label :for="`parent-${item.key}`" class="text-xs font-medium text-muted-foreground">{{ t('items.fields.parent') }}</label>
              <input
                :id="`parent-${item.key}`" v-model="parentDraft" type="text" :placeholder="t('items.detail.parentPlaceholder')"
                :class="[inputClass, 'font-mono uppercase placeholder:font-sans placeholder:normal-case']"
              />
              <div class="flex items-center justify-between gap-2">
                <Button v-if="item.parent" variant="outline" size="sm" class="h-8" @click="openParent">{{ t('items.detail.openParentKey', { key: item.parent }) }}</Button>
                <Button type="submit" size="sm" class="ml-auto h-8">{{ t('common.save') }}</Button>
              </div>
            </form>
          </ChipPopover>
          <PropertyChip v-else-if="item.parent" :label="t('items.detail.openParentKey', { key: item.parent })" class="h-6 font-mono" @click="emit('open', item.parent)">
            <CornerLeftUp class="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />{{ item.parent }}
          </PropertyChip>
        </template>
      </div>

      <div class="flex shrink-0 items-center gap-0.5">
        <Button
          v-if="item" variant="ghost" size="sm" class="h-8 gap-1.5 px-2" :aria-pressed="item.subscribed" :aria-label="subscribeLabel" :title="subscribeLabel"
          @click="emit('toggleSubscription')"
        >
          <component :is="item.subscribed ? Bell : BellOff" class="h-4 w-4" />
          <span v-if="variant === 'page'" class="hidden sm:inline">{{ item.subscribed ? t('items.detail.subscribed') : t('items.detail.subscribe') }}</span>
        </Button>
        <DropdownMenu v-if="item" align="end">
          <template #trigger>
            <Button variant="ghost" size="icon" class="h-8 w-8" :aria-label="t('items.detail.more')" :title="t('items.detail.more')"><Ellipsis class="h-4 w-4" /></Button>
          </template>
          <DropdownMenuItem @select="copyLink(item.key)"><Link2 class="h-4 w-4" />{{ t('items.detail.copyLink') }}</DropdownMenuItem>
          <DropdownMenuItem v-if="canEdit" class="text-destructive focus:text-destructive" @select="emit('delete')">
            <Trash2 class="h-4 w-4" />{{ t('items.detail.deleteItem') }}
          </DropdownMenuItem>
        </DropdownMenu>
        <template v-if="variant === 'peek'">
          <Button
            variant="ghost" size="sm" class="h-8 gap-1.5 px-2" :aria-label="t('items.detail.expandHint')" :title="t('items.detail.expandHint')"
            @click="emit('expand')"
          >
            <Maximize2 class="h-4 w-4" /><span class="hidden sm:inline">{{ t('items.detail.expand') }}</span><Kbd keys="f" class="hidden lg:inline-flex" />
          </Button>
          <Button variant="ghost" size="icon" class="h-8 w-8" :aria-label="t('common.close')" :title="t('common.close')" @click="emit('close')"><X class="h-4 w-4" /></Button>
        </template>
        <Button
          v-else variant="ghost" size="sm" class="h-8 gap-1.5 px-2" :aria-label="t('items.detail.collapseHint')" :title="t('items.detail.collapseHint')"
          @click="emit('collapse')"
        >
          <Minimize2 class="h-4 w-4" /><span class="hidden sm:inline">{{ t('items.detail.collapse') }}</span><Kbd keys="f" class="hidden lg:inline-flex" />
        </Button>
      </div>
    </div>

    <template v-if="item">
      <input
        v-model="title" type="text" maxlength="255" :disabled="!canEdit" :aria-label="t('items.fields.title')"
        class="-mx-2 mt-1 w-[calc(100%+1rem)] truncate rounded-md border border-transparent bg-transparent px-2 py-1 text-xl font-semibold tracking-tight
               hover:border-input focus-visible:border-input focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:hover:border-transparent"
        @blur="saveTitle" @keydown.enter.prevent="($event.target as HTMLInputElement).blur()"
      />

      <div class="mt-2 flex flex-wrap items-center gap-1.5" role="group" :aria-label="t('items.detail.properties')">
        <PropertyPicker v-bind="picker('state')">
          <template #trigger="{ summary }">
            <PropertyChip :label="t('items.fields.state')" :value="summary" :disabled="!canEdit">
              <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: item.state.color }" aria-hidden="true" />
              <span class="truncate font-medium">{{ item.state.name }}</span>
            </PropertyChip>
          </template>
        </PropertyPicker>

        <PropertyPicker v-if="canEdit || item.priority !== 'none'" v-bind="picker('priority')">
          <template #trigger="{ summary }">
            <PropertyChip :label="t('items.fields.priority')" :value="summary" :empty="item.priority === 'none'" :disabled="!canEdit">
              <SignalMedium v-if="item.priority === 'none'" class="h-4 w-4 shrink-0 opacity-60" aria-hidden="true" />
              <template v-else>
                <component :is="PRIORITY_ICONS[item.priority]" class="h-4 w-4 shrink-0" :class="item.priority === 'urgent' ? 'text-red-600' : 'text-muted-foreground'" aria-hidden="true" />
                <span class="truncate">{{ summary }}</span>
              </template>
            </PropertyChip>
          </template>
        </PropertyPicker>

        <PropertyPicker v-if="canEdit || item.assignee" v-bind="picker('assignee')">
          <template #trigger>
            <PropertyChip :label="t('items.fields.assignee')" :value="item.assignee?.name ?? t('items.unassigned')" :empty="!item.assignee" :disabled="!canEdit" :class="item.assignee ? 'pl-0.5' : ''">
              <template v-if="item.assignee">
                <UserInitials :user="item.assignee" class="!h-5 !w-5 !text-[9px]" /><span class="truncate">{{ item.assignee.name }}</span>
              </template>
              <User v-else class="h-4 w-4 shrink-0" aria-hidden="true" />
            </PropertyChip>
          </template>
        </PropertyPicker>

        <ChipPopover v-if="canEdit || item.due_date" v-model:open="dueOpen" :label="t('items.fields.dueDate')" :disabled="!canEdit">
          <template #trigger>
            <PropertyChip
              :label="t('items.fields.dueDate')" :value="item.due_date ? formatDate(item.due_date, locale) : null" :empty="!item.due_date" :disabled="!canEdit"
              :class="overdue ? 'border-red-600/40 font-medium text-red-600' : ''"
            >
              <CalendarDays class="h-3.5 w-3.5 shrink-0" :class="overdue ? '' : 'text-muted-foreground'" aria-hidden="true" />
              <span v-if="item.due_date">{{ formatDate(item.due_date, locale) }}</span>
            </PropertyChip>
          </template>
          <div class="space-y-2">
            <label :for="`due-${item.key}`" class="text-xs font-medium text-muted-foreground">{{ t('items.fields.dueDate') }}</label>
            <input
              :id="`due-${item.key}`" type="date" :value="item.due_date ?? ''" :class="inputClass"
              @change="pickDueDate(($event.target as HTMLInputElement).value || null)"
            />
            <Button v-if="item.due_date" variant="outline" size="sm" class="h-8" @click="pickDueDate(null)">{{ t('items.detail.clearDueDate') }}</Button>
          </div>
        </ChipPopover>

        <template v-if="variant === 'peek'">
          <PropertyPicker v-if="canEdit || item.labels.length" v-bind="picker('labels')">
            <template #trigger>
              <PropertyChip
                :label="t('items.fields.labels')" :value="item.labels.map((l) => l.name).join(', ') || t('items.noLabels')" :empty="!item.labels.length" :disabled="!canEdit"
              >
                <Tag v-if="!item.labels.length" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <template v-else>
                  <span v-for="label in item.labels.slice(0, 2)" :key="label.id" class="inline-flex min-w-0 items-center gap-1">
                    <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: label.color }" aria-hidden="true" />
                    <span class="truncate">{{ label.name }}</span>
                  </span>
                  <span v-if="item.labels.length > 2" class="shrink-0 text-muted-foreground">+{{ item.labels.length - 2 }}</span>
                </template>
              </PropertyChip>
            </template>
          </PropertyPicker>

          <PropertyPicker
            v-if="item.delegate || (canEdit && agents.length > 0)" v-model:open="delegateOpen" :label="t('items.fields.delegate')" :options="delegateOptions"
            :model-value="item.delegate ? String(item.delegate.id) : ''" :disabled="!canEdit" @update:model-value="delegateTo($event)"
          >
            <template #trigger>
              <PropertyChip :label="t('items.fields.delegate')" :value="item.delegate?.name ?? t('items.noDelegate')" :empty="!item.delegate" :disabled="!canEdit">
                <Bot class="h-4 w-4 shrink-0" :class="item.delegate ? 'text-muted-foreground' : ''" aria-hidden="true" />
                <span v-if="item.delegate" class="truncate">{{ item.delegate.name }}</span>
              </PropertyChip>
            </template>
          </PropertyPicker>

          <PropertyPicker
            v-if="item.cycle || (canEdit && hasCycles)" v-model:open="cycleOpen" :label="t('items.fields.cycle')" :options="cycleOptions"
            :model-value="item.cycle ? String(item.cycle.number) : ''" :disabled="!canEdit" @update:model-value="setCycle($event)"
          >
            <template #trigger>
              <PropertyChip :label="t('items.fields.cycle')" :value="item.cycle?.name ?? t('items.noCycle')" :empty="!item.cycle" :disabled="!canEdit">
                <RefreshCw class="h-3.5 w-3.5 shrink-0" :class="item.cycle ? 'text-muted-foreground' : ''" aria-hidden="true" />
                <span v-if="item.cycle" class="truncate">{{ item.cycle.name }}</span>
              </PropertyChip>
            </template>
          </PropertyPicker>
        </template>

        <span v-if="item.blocked" data-mark="blocked" class="inline-flex h-7 items-center gap-1 rounded-md bg-destructive/10 px-2 text-xs font-medium text-destructive">
          <Ban class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ blockers ? t('items.blockedBy', { items: blockers }) : t('items.blocked') }}
        </span>
        <span v-if="item.needs_input" class="inline-flex items-center gap-1.5">
          <NeedsInputBadge />
          <button
            v-if="canEdit" type="button"
            class="rounded text-xs text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            @click="update(item.key, { needs_input: false })"
          >{{ t('items.markAnswered') }}</button>
        </span>
        <span v-if="item.demand > 0" data-mark="demand" class="inline-flex h-7 items-center gap-1 px-1 text-xs text-muted-foreground">
          <Megaphone class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ t('items.demand', item.demand) }}
        </span>
      </div>
    </template>
  </header>
</template>
