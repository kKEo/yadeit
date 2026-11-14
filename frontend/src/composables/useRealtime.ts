import { useQueryCache, type QueryCache } from '@pinia/colada'
import { onScopeDispose, watch } from 'vue'
import { connectRealtime, realtimeConfig, type RealtimeConnection } from '@/realtime/echo'
import { keys } from '@/queries/keys'
import { useAuthStore } from '@/stores/auth'
import { useWorkspaceStore } from '@/stores/workspace'

export interface ItemChangedPayload { key: string; event: string; actor_id: number | null }
export interface InboxChangedPayload { workspace_id: number }

/**
 * Someone changed an item: re-read the lists and that item's detail, comments
 * and activity. Our own changes are already in the cache, so they are skipped.
 * Returns whether anything was invalidated.
 */
export function handleItemChanged(cache: QueryCache, slug: string, meId: number | null, payload: ItemChangedPayload): boolean {
  if (payload.actor_id !== null && payload.actor_id === meId) return false
  void cache.invalidateQueries({ key: keys.itemLists(slug) })
  if (payload.key) void cache.invalidateQueries({ key: keys.item(slug, payload.key) })
  return true
}

/** The user's inbox changed; the signal goes to all their workspaces, so only the open one reacts. */
export function handleInboxChanged(cache: QueryCache, slug: string, workspaceId: number, payload: InboxChangedPayload): boolean {
  if (payload.workspace_id !== workspaceId) return false
  void cache.invalidateQueries({ key: keys.inbox(slug) })
  return true
}

/**
 * Keeps the open workspace live. Connects only when Reverb is configured and
 * someone is signed in; reconnects for another workspace and disconnects on
 * sign-out or when the workspace pages are left.
 */
export function useRealtime(connect: typeof connectRealtime = connectRealtime) {
  const config = realtimeConfig()
  if (!config) return

  const cache = useQueryCache()
  const auth = useAuthStore()
  const workspace = useWorkspaceStore()
  let connection: RealtimeConnection | null = null
  let generation = 0

  function disconnect() {
    generation++
    connection?.disconnect()
    connection = null
  }

  watch(
    () => [auth.user?.id ?? null, workspace.current?.id ?? null, workspace.current?.slug ?? null] as const,
    async ([userId, workspaceId, slug]) => {
      disconnect()
      if (userId === null || workspaceId === null || slug === null) return
      const mine = generation
      try {
        const opened = await connect(config, workspaceId, userId, {
          onItemChanged: (payload) => handleItemChanged(cache, slug, userId, payload),
          onInboxChanged: (payload) => handleInboxChanged(cache, slug, workspaceId, payload),
        })
        // The user moved on while the socket was opening.
        if (mine !== generation) opened.disconnect()
        else connection = opened
      } catch {
        // Realtime is an extra: without it the app still works, just without live updates.
      }
    },
    { immediate: true },
  )

  onScopeDispose(disconnect)
}
