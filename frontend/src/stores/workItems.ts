import { defineStore } from 'pinia'
import { ref } from 'vue'
import type {
  CreateWorkItemRequest,
  FieldErrors,
  ListWorkItemsParams,
  UpdateWorkItemRequest,
  WorkItem,
  WorkItemSummary,
} from '@/api/types'
import * as api from '@/api/client'
import { ApiError } from '@/api/client'
import i18n from '@/i18n'
import { useWorkspaceStore } from '@/stores/workspace'

export const useWorkItemsStore = defineStore('workItems', () => {
  // Work items live inside the workspace named in the URL.
  const workspaces = useWorkspaceStore()

  const list = ref<WorkItemSummary[]>([])
  const nextCursor = ref<string | null>(null)
  const current = ref<WorkItem | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const fieldErrors = ref<FieldErrors>({})

  // ETag map: work item key → etag (for optimistic concurrency via If-Match)
  const etags = ref<Record<string, string>>({})

  // Guards against a slow response overwriting a newer one (filter switch, polling)
  let listRequest = 0

  function fail(err: unknown, fallbackKey: string) {
    error.value = err instanceof Error ? err.message : i18n.global.t(fallbackKey)
    fieldErrors.value = err instanceof ApiError ? err.fields : {}
  }

  function reset() {
    error.value = null
    fieldErrors.value = {}
  }

  function remember(res: api.WithEtag<WorkItem>) {
    if (res.etag) etags.value[res.item.key] = res.etag
    else delete etags.value[res.item.key]
  }

  /** Loads the first page, or appends the next one when `params.cursor` is given. */
  async function fetchList(params?: ListWorkItemsParams): Promise<void> {
    const id = ++listRequest
    loading.value = true
    reset()
    try {
      const res = await api.listWorkItems(workspaces.requireSlug(), params)
      if (id !== listRequest) return
      list.value = params?.cursor ? [...list.value, ...res.data] : res.data
      nextCursor.value = res.next_cursor
    } catch (err: unknown) {
      if (id === listRequest) fail(err, 'errors.fetchList')
    } finally {
      if (id === listRequest) loading.value = false
    }
  }

  async function fetchOne(key: string): Promise<void> {
    if (current.value?.key !== key) current.value = null
    loading.value = true
    reset()
    try {
      const res = await api.getWorkItem(workspaces.requireSlug(), key)
      current.value = res.item
      remember(res)
    } catch (err: unknown) {
      fail(err, 'errors.fetchOne')
    } finally {
      loading.value = false
    }
  }

  async function create(req: CreateWorkItemRequest): Promise<WorkItem | null> {
    loading.value = true
    reset()
    try {
      const res = await api.createWorkItem(workspaces.requireSlug(), req)
      remember(res)
      return res.item
    } catch (err: unknown) {
      fail(err, 'errors.create')
      return null
    } finally {
      loading.value = false
    }
  }

  async function update(key: string, req: UpdateWorkItemRequest): Promise<boolean> {
    reset()
    try {
      const res = await api.updateWorkItem(workspaces.requireSlug(), key, req, etags.value[key])
      if (current.value?.key === key) current.value = res.item
      remember(res)
      return true
    } catch (err: unknown) {
      fail(err, 'errors.update')
      return false
    }
  }

  async function remove(key: string): Promise<boolean> {
    loading.value = true
    reset()
    try {
      await api.deleteWorkItem(workspaces.requireSlug(), key, etags.value[key])
      delete etags.value[key]
      list.value = list.value.filter((item) => item.key !== key)
      if (current.value?.key === key) current.value = null
      return true
    } catch (err: unknown) {
      fail(err, 'errors.delete')
      return false
    } finally {
      loading.value = false
    }
  }

  function clearError() {
    reset()
  }

  return {
    list, nextCursor, current, loading, error, fieldErrors, etags,
    fetchList, fetchOne, create, update, remove, clearError,
  }
})
