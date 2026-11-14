<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/items'
import type { Comment } from '@/api/types'
import { formatDateTime, formatRelative } from '@/lib/format'
import { roleAtLeast } from '@/lib/roles'
import { useItemPartMutation } from '@/queries/itemMutations'
import { useItemComments } from '@/queries/items'
import { useAuthStore } from '@/stores/auth'
import { useWorkspaceStore } from '@/stores/workspace'
import Button from '@/components/ui/Button.vue'
import Kbd from '@/components/ui/Kbd.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Textarea from '@/components/ui/Textarea.vue'
import MarkdownView from './MarkdownView.vue'
import UserInitials from './UserInitials.vue'

/** The comment thread. Everyone in the workspace, guests included, may comment. */
const props = defineProps<{ itemKey: string }>()
const { t, locale } = useI18n()
const auth = useAuthStore()
const workspace = useWorkspaceStore()
const comments = useItemComments(() => props.itemKey)

const draft = ref('')
const editingId = ref<number | null>(null)
const editDraft = ref('')

const parts = ['comments', 'detail', 'activity'] as const
const add = useItemPartMutation(() => props.itemKey, (s, k, body: string) => api.addComment(s, k, body), parts)
const edit = useItemPartMutation(() => props.itemKey, (s, k, v: { id: number; body: string }) => api.updateComment(s, k, v.id, v.body), ['comments'])
const remove = useItemPartMutation(() => props.itemKey, (s, k, id: number) => api.deleteComment(s, k, id), parts)

const isMine = (comment: Comment) => comment.author?.id === auth.user?.id
const canDelete = (comment: Comment) => isMine(comment) || roleAtLeast(workspace.role, 'admin')

async function submit() {
  const body = draft.value.trim()
  if (!body || add.isLoading.value) return
  try {
    await add.mutateAsync(body)
    draft.value = ''
  } catch {
    // reported as a toast; the draft stays
  }
}

function startEdit(comment: Comment) {
  editingId.value = comment.id
  editDraft.value = comment.body
}

async function saveEdit() {
  const body = editDraft.value.trim()
  if (!body || editingId.value === null) return
  try {
    await edit.mutateAsync({ id: editingId.value, body })
    editingId.value = null
  } catch {
    // reported as a toast; keep editing
  }
}
</script>

<template>
  <div class="space-y-4">
    <div v-if="comments.status.value === 'pending'" class="space-y-2" aria-busy="true">
      <Skeleton class="h-12 w-full" />
    </div>
    <p v-else-if="!comments.data.value?.length" class="text-sm text-muted-foreground">{{ t('items.comments.empty') }}</p>
    <ol v-else class="space-y-4">
      <li v-for="comment in comments.data.value" :key="comment.id" class="flex gap-3">
        <UserInitials :user="comment.author" class="mt-0.5" />
        <div class="min-w-0 flex-1">
          <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
            <span class="font-medium">{{ comment.author?.name ?? t('activity.someone') }}</span>
            <time :datetime="comment.created_at" :title="formatDateTime(comment.created_at, locale)" class="text-xs text-muted-foreground">
              {{ formatRelative(comment.created_at, locale) }}
            </time>
            <span v-if="comment.edited_at" class="text-xs text-muted-foreground">{{ t('items.comments.edited') }}</span>
          </p>
          <form v-if="editingId === comment.id" class="mt-1 space-y-2" @submit.prevent="saveEdit" @keydown.esc.stop="editingId = null">
            <Textarea
              v-model="editDraft" :rows="3" :aria-label="t('items.comments.editLabel')"
              @keydown.meta.enter.prevent="saveEdit" @keydown.ctrl.enter.prevent="saveEdit"
            />
            <div class="flex justify-end gap-2">
              <Button variant="outline" size="sm" @click="editingId = null">{{ t('common.cancel') }}</Button>
              <Button type="submit" size="sm" :disabled="!editDraft.trim() || edit.isLoading.value">{{ t('common.save') }}</Button>
            </div>
          </form>
          <template v-else>
            <MarkdownView :source="comment.body" class="mt-1" />
            <p v-if="isMine(comment) || canDelete(comment)" class="mt-1 flex gap-3 text-xs text-muted-foreground">
              <button v-if="isMine(comment)" type="button" class="rounded hover:text-foreground hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" @click="startEdit(comment)">
                {{ t('items.comments.edit') }}
              </button>
              <button v-if="canDelete(comment)" type="button" class="rounded hover:text-destructive hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" @click="remove.mutate(comment.id)">
                {{ t('common.delete') }}
              </button>
            </p>
          </template>
        </div>
      </li>
    </ol>

    <form class="space-y-2" @submit.prevent="submit">
      <Textarea
        v-model="draft" :rows="3" :aria-label="t('items.comments.add')" :placeholder="t('items.comments.placeholder')"
        @keydown.meta.enter.prevent="submit" @keydown.ctrl.enter.prevent="submit"
      />
      <div class="flex items-center justify-end gap-2">
        <Kbd keys="mod+enter" />
        <Button type="submit" size="sm" :disabled="!draft.trim() || add.isLoading.value">
          {{ add.isLoading.value ? t('items.comments.sending') : t('items.comments.submit') }}
        </Button>
      </div>
    </form>
  </div>
</template>
