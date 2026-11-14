<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Bookmark, Check, ChevronDown, Pencil, Plus, Save, Trash2, Users } from '@lucide/vue'
import type { SavedView, ViewState } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { roleAtLeast } from '@/lib/roles'
import { BUILT_IN_VIEWS, DEFAULT_VIEW, sameViewState, viewPayload, viewToState } from '@/lib/viewState'
import { useSavedViews, useViewMutations } from '@/queries/views'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useWorkspaceStore } from '@/stores/workspace'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import Button from '@/components/ui/Button.vue'
import DropdownMenu from '@/components/ui/DropdownMenu.vue'
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue'
import DropdownMenuLabel from '@/components/ui/DropdownMenuLabel.vue'
import DropdownMenuSeparator from '@/components/ui/DropdownMenuSeparator.vue'
import SaveViewDialog from './SaveViewDialog.vue'

/** Built-in and saved views: pick one, save the current state as a new one, update, rename or delete. */
const props = defineProps<{ state: ViewState; viewId: string | null }>()
const emit = defineEmits<{ apply: [state: ViewState, viewId: string | null] }>()
const { t } = useI18n()
const auth = useAuthStore()
const workspace = useWorkspaceStore()
const toast = useToastStore()
const views = useSavedViews()
const { create, update, remove } = useViewMutations()
const form = useApiForm(['name'])

const saved = computed(() => views.data.value ?? [])
const currentSaved = computed<SavedView | null>(() => saved.value.find((v) => String(v.id) === props.viewId) ?? null)
const currentBuiltIn = computed(() => (
  BUILT_IN_VIEWS.find((v) => v.id === props.viewId)
    ?? (props.viewId === null && sameViewState(props.state, DEFAULT_VIEW) ? BUILT_IN_VIEWS[0] : null)
))
const title = computed(() => (
  currentSaved.value?.name ?? (currentBuiltIn.value ? t(`views.builtIn.${currentBuiltIn.value.id}`) : t('views.custom'))
))
/** The state has moved away from the view it started from. */
const dirty = computed(() => {
  const base = currentSaved.value ? viewToState(currentSaved.value) : currentBuiltIn.value?.state
  return base ? !sameViewState(props.state, base) : false
})
const canShare = computed(() => roleAtLeast(workspace.role, 'member'))
const canEditCurrent = computed(() => (
  currentSaved.value !== null && (currentSaved.value.owner_id === auth.user?.id || roleAtLeast(workspace.role, 'admin'))
))

const dialog = ref<'create' | 'edit' | null>(null)
const dialogOpen = computed({ get: () => dialog.value !== null, set: (open) => { if (!open) dialog.value = null } })
const deleteOpen = ref(false)

async function submitDialog(value: { name: string; is_shared: boolean }) {
  const mode = dialog.value
  const ok = await form.submit(async () => {
    if (mode === 'create') {
      const view = await create.mutateAsync({ ...value, ...viewPayload(props.state) })
      emit('apply', viewToState(view), String(view.id))
    } else if (currentSaved.value) {
      await update.mutateAsync({ id: currentSaved.value.id, ...value })
    }
  })
  if (ok) {
    dialog.value = null
    toast.success(t('views.savedToast', { name: value.name }))
  }
}

async function saveChanges() {
  const view = currentSaved.value
  if (!view) return
  try {
    await update.mutateAsync({ id: view.id, ...viewPayload(props.state) })
    toast.success(t('views.savedToast', { name: view.name }))
  } catch (err: unknown) {
    toast.error(err instanceof Error ? err.message : t('errors.generic'))
  }
}

async function confirmDelete() {
  const view = currentSaved.value
  if (!view) return
  try {
    await remove.mutateAsync(view.id)
    emit('apply', props.state, null)
  } catch (err: unknown) {
    toast.error(err instanceof Error ? err.message : t('errors.generic'))
  }
  deleteOpen.value = false
}

function openDialog(mode: 'create' | 'edit') {
  form.clear()
  dialog.value = mode
}
</script>

<template>
  <div class="flex items-center gap-2">
    <DropdownMenu class="max-h-[70vh] w-64 overflow-y-auto">
      <template #trigger>
        <Button variant="outline" size="sm" class="max-w-[16rem] gap-2" :aria-label="t('views.menu', { view: title })">
          <Bookmark class="h-4 w-4 shrink-0" />
          <span class="truncate">{{ title }}</span>
          <span v-if="dirty" class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" :title="t('views.unsaved')" />
          <ChevronDown class="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </template>
      <DropdownMenuItem v-for="view in BUILT_IN_VIEWS" :key="view.id" @select="emit('apply', view.state, view.id)">
        <Check class="h-4 w-4" :class="currentBuiltIn?.id === view.id && !currentSaved ? 'opacity-100' : 'opacity-0'" />
        {{ t(`views.builtIn.${view.id}`) }}
      </DropdownMenuItem>
      <template v-if="saved.length">
        <DropdownMenuSeparator />
        <DropdownMenuLabel>{{ t('views.saved') }}</DropdownMenuLabel>
        <DropdownMenuItem v-for="view in saved" :key="view.id" @select="emit('apply', viewToState(view), String(view.id))">
          <Check class="h-4 w-4 shrink-0" :class="currentSaved?.id === view.id ? 'opacity-100' : 'opacity-0'" />
          <span class="min-w-0 flex-1 truncate">{{ view.name }}</span>
          <Users v-if="view.is_shared" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" :aria-label="t('views.sharedBadge')" />
        </DropdownMenuItem>
      </template>
      <DropdownMenuSeparator />
      <DropdownMenuItem @select="openDialog('create')"><Plus class="h-4 w-4" />{{ t('views.saveAs') }}</DropdownMenuItem>
      <template v-if="canEditCurrent">
        <DropdownMenuItem :disabled="!dirty" @select="saveChanges"><Save class="h-4 w-4" />{{ t('views.update') }}</DropdownMenuItem>
        <DropdownMenuItem @select="openDialog('edit')"><Pencil class="h-4 w-4" />{{ t('views.rename') }}</DropdownMenuItem>
        <DropdownMenuItem @select="deleteOpen = true"><Trash2 class="h-4 w-4" />{{ t('views.delete') }}</DropdownMenuItem>
      </template>
    </DropdownMenu>

    <Button v-if="dirty && canEditCurrent" size="sm" variant="secondary" @click="saveChanges">{{ t('views.update') }}</Button>
    <Button v-else-if="dirty" size="sm" variant="secondary" @click="openDialog('create')">{{ t('views.save') }}</Button>

    <SaveViewDialog
      v-model:open="dialogOpen" :mode="dialog ?? 'create'" :can-share="canShare"
      :initial-name="dialog === 'edit' ? currentSaved?.name : ''" :initial-shared="dialog === 'edit' ? currentSaved?.is_shared : false"
      :busy="form.pending.value" :error="form.error.value" :errors="form.fieldErrors.value.name"
      @submit="submitDialog"
    />
    <ConfirmDialog
      v-model:open="deleteOpen" destructive
      :title="t('views.deleteTitle', { name: currentSaved?.name ?? '' })" :description="t('views.deleteDescription')"
      :confirm-label="t('common.delete')" :busy="remove.isLoading.value" @confirm="confirmDelete"
    />
  </div>
</template>
