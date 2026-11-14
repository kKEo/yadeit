import { useMutation, useQueryCache, type QueryCache } from '@pinia/colada'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/api/client'
import * as api from '@/api/items'
import type {
  CreateItemRequest, CycleRef, ItemDetail, ItemKind, LabelRef, Priority, StateRef, UpdateItemRequest, UserRef,
} from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { findCachedItem, insertCachedItem, patchCachedItem, removeCachedItem, writeCachedDetail } from './itemCache'
import { keys, useSlug } from './keys'

/** A change to an item, with the values as they are displayed (objects, not ids). */
export interface ItemPatch {
  title?: string
  description?: string | null
  state?: StateRef
  priority?: Priority
  kind?: ItemKind
  assignee?: UserRef | null
  delegate?: UserRef | null
  needs_input?: boolean
  cycle?: CycleRef | null
  labels?: LabelRef[]
  due_date?: string | null
  parent?: string | null
}

/** The request body for a patch: references by id or name, the way the API takes them. */
export function patchRequest(patch: ItemPatch): UpdateItemRequest {
  const { state, assignee, delegate, labels, cycle, ...plain } = patch
  const req: UpdateItemRequest = { ...plain }
  if (state !== undefined) req.state = state.id
  if (assignee !== undefined) req.assignee = assignee?.id ?? null
  if (delegate !== undefined) req.delegate = delegate?.id ?? null
  if (cycle !== undefined) req.cycle = cycle?.number ?? null
  if (labels !== undefined) req.labels = labels.map((label) => label.name)
  return req
}

/** What the patch looks like on a cached row, including what the server will derive from it. */
function cachedForm(patch: ItemPatch, current: { completed_at: string | null } | undefined): Partial<ItemDetail> {
  const form: Partial<ItemDetail> = { ...patch }
  if (patch.state) {
    const closed = patch.state.group === 'completed' || patch.state.group === 'cancelled'
    form.completed_at = closed ? (current?.completed_at ?? new Date().toISOString()) : null
  }
  return form
}

// Changes to one item go out one after another, so each can send the version the previous one produced.
const chains = new Map<string, Promise<unknown>>()
const inFlight = new Map<string, number>()

function enqueue<T>(id: string, task: () => Promise<T>): Promise<T> {
  const run = (chains.get(id) ?? Promise.resolve()).catch(() => undefined).then(task)
  chains.set(id, run)
  return run
}

function count(id: string, delta: number): number {
  const next = (inFlight.get(id) ?? 0) + delta
  if (next <= 0) inFlight.delete(id)
  else inFlight.set(id, next)
  return next
}

export function isVersionMismatch(err: unknown): boolean {
  return err instanceof ApiError && err.status === 412
}

/** After a conflict or a failed save, re-read what the server has. */
export function refetchItem(cache: QueryCache, slug: string, key: string): Promise<unknown> {
  return Promise.all([
    cache.invalidateQueries({ key: keys.item(slug, key) }),
    cache.invalidateQueries({ key: keys.itemLists(slug) }),
  ])
}

interface UpdateVars { slug: string; key: string; patch: ItemPatch }

/**
 * Optimistic property changes: the cached rows and detail change at once, the
 * request carries `expected_version`, and a failure puts the old values back
 * and says why. A 412 means someone else changed the item: it is re-read.
 */
export function useUpdateItem() {
  const cache = useQueryCache()
  const slug = useSlug()
  const toast = useToastStore()
  const { t } = useI18n()

  const mutation = useMutation({
    mutation: ({ slug, key, patch }: UpdateVars) => enqueue(`${slug}/${key}`, () => (
      api.updateItem(slug, key, { ...patchRequest(patch), expected_version: findCachedItem(cache, slug, key)?.version })
    )),
    onMutate({ slug, key, patch }) {
      const current = findCachedItem(cache, slug, key) as Partial<ItemDetail> | undefined
      const optimistic = cachedForm(patch, current as { completed_at: string | null } | undefined)
      const previous: Partial<ItemDetail> = {}
      if (current) {
        for (const field of Object.keys(optimistic) as (keyof ItemDetail)[]) {
          if (field in current) Object.assign(previous, { [field]: current[field] })
        }
      }
      patchCachedItem(cache, slug, key, optimistic)
      count(`${slug}/${key}`, 1)
      return { previous }
    },
    onSuccess(detail, { slug, key }) {
      // With more changes to this item still on their way, only take the new version: their optimistic values stay.
      if ((inFlight.get(`${slug}/${key}`) ?? 0) > 1) {
        patchCachedItem(cache, slug, key, { version: detail.version, updated_at: detail.updated_at })
      } else {
        writeCachedDetail(cache, slug, detail)
      }
      void cache.invalidateQueries({ key: keys.itemActivity(slug, key) })
    },
    onError(err, { slug, key }, context) {
      if (context.previous) patchCachedItem(cache, slug, key, context.previous)
      if (isVersionMismatch(err)) {
        toast.error(t('items.toast.changedElsewhere', { key }))
        void refetchItem(cache, slug, key)
      } else {
        toast.error(err instanceof Error && err.message ? err.message : t('errors.update'))
      }
    },
    onSettled(_data, _error, { slug, key }) {
      count(`${slug}/${key}`, -1)
    },
  })

  /** Resolves to whether the change was saved; failures are already reported. */
  async function update(key: string, patch: ItemPatch): Promise<boolean> {
    if (!slug.value) return false
    try {
      await mutation.mutateAsync({ slug: slug.value, key, patch })
      return true
    } catch {
      return false
    }
  }

  return { update, isLoading: mutation.isLoading }
}

/** Creates an item and shows it in the cached lists it belongs to straight away. */
export function useCreateItem() {
  const cache = useQueryCache()
  const slug = useSlug()
  const auth = useAuthStore()

  return useMutation({
    // The key travels with the variables so a retry of the same submit is recognised by the API.
    mutation: ({ req, idempotencyKey }: { req: CreateItemRequest; idempotencyKey: string }) => (
      api.createItem(slug.value, req, idempotencyKey)
    ),
    onSuccess(detail) {
      insertCachedItem(cache, slug.value, detail, auth.user?.id ?? null)
      if (detail.parent) void cache.invalidateQueries({ key: keys.item(slug.value, detail.parent) })
    },
  })
}

export function useDeleteItem() {
  const cache = useQueryCache()
  const slug = useSlug()

  return useMutation({
    mutation: (key: string) => api.deleteItem(slug.value, key),
    onSuccess(_data, key) {
      removeCachedItem(cache, slug.value, key)
      void cache.invalidateQueries({ key: keys.itemLists(slug.value) })
    },
  })
}

type Part = 'detail' | 'comments' | 'activity'

/**
 * A change to one of an item's parts (comment, checklist entry, link, relation,
 * attachment, subscription). Afterwards the affected queries are re-read; a
 * failure is reported as a toast and rethrown by `mutateAsync`.
 */
export function useItemPartMutation<TVars, TData = unknown>(
  itemKey: () => string,
  action: (slug: string, key: string, vars: TVars) => Promise<TData>,
  refresh: readonly Part[] = ['detail', 'activity'],
) {
  const cache = useQueryCache()
  const slug = useSlug()
  const toast = useToastStore()
  const { t } = useI18n()

  return useMutation({
    mutation: (vars: TVars) => action(slug.value, itemKey(), vars),
    onMutate: () => ({ slug: slug.value, key: itemKey() }),
    onSettled(_data, _error, _vars, context) {
      if (!context.slug || !context.key) return
      const byPart: Record<Part, ReturnType<typeof keys.itemDetail>> = {
        detail: keys.itemDetail(context.slug, context.key),
        comments: keys.itemComments(context.slug, context.key),
        activity: keys.itemActivity(context.slug, context.key),
      }
      for (const part of refresh) void cache.invalidateQueries({ key: byPart[part] })
    },
    onError(err) {
      toast.error(err instanceof Error && err.message ? err.message : t('errors.generic'))
    },
  })
}
