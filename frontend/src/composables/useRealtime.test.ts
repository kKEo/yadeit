import { describe, expect, it, vi } from 'vitest'
import type { QueryCache } from '@pinia/colada'
import { realtimeConfig } from '@/realtime/echo'
import { handleInboxChanged, handleItemChanged } from './useRealtime'

function fakeCache() {
  const invalidateQueries = vi.fn((_filter: { key: unknown[] }) => Promise.resolve())
  return { cache: { invalidateQueries } as unknown as QueryCache, invalidateQueries }
}

describe('realtime handlers', () => {
  it('ignores item changes made by the current user', () => {
    const { cache, invalidateQueries } = fakeCache()

    expect(handleItemChanged(cache, 'acme', 7, { key: 'WEB-1', event: 'updated', actor_id: 7 })).toBe(false)
    expect(invalidateQueries).not.toHaveBeenCalled()
  })

  it('invalidates the lists and the item (detail, comments, activity) on changes by others', () => {
    const { cache, invalidateQueries } = fakeCache()

    expect(handleItemChanged(cache, 'acme', 7, { key: 'WEB-1', event: 'commented', actor_id: 9 })).toBe(true)

    expect(invalidateQueries.mock.calls.map(([filter]) => filter)).toEqual([
      { key: ['w', 'acme', 'items'] },
      // The prefix covers ['w','acme','item','WEB-1','detail' | 'comments' | 'activity'].
      { key: ['w', 'acme', 'item', 'WEB-1'] },
    ])
  })

  it('treats changes without an actor (system) as someone else\'s', () => {
    const { cache, invalidateQueries } = fakeCache()
    expect(handleItemChanged(cache, 'acme', 7, { key: 'WEB-2', event: 'updated', actor_id: null })).toBe(true)
    expect(invalidateQueries).toHaveBeenCalledTimes(2)
  })

  it('invalidates the inbox only for the open workspace', () => {
    const { cache, invalidateQueries } = fakeCache()

    expect(handleInboxChanged(cache, 'acme', 1, { workspace_id: 2 })).toBe(false)
    expect(invalidateQueries).not.toHaveBeenCalled()

    expect(handleInboxChanged(cache, 'acme', 1, { workspace_id: 1 })).toBe(true)
    expect(invalidateQueries).toHaveBeenCalledWith({ key: ['w', 'acme', 'inbox'] })
  })
})

describe('realtime configuration', () => {
  it('is off without a key', () => {
    expect(realtimeConfig({})).toBeNull()
    expect(realtimeConfig({ VITE_REVERB_APP_KEY: '  ', VITE_REVERB_HOST: 'localhost' })).toBeNull()
  })

  it('reads host, port and scheme from the env', () => {
    expect(realtimeConfig({ VITE_REVERB_APP_KEY: 'k', VITE_REVERB_HOST: 'ws.example.com', VITE_REVERB_PORT: '443', VITE_REVERB_SCHEME: 'https' }))
      .toEqual({ key: 'k', host: 'ws.example.com', port: 443, scheme: 'https' })
    expect(realtimeConfig({ VITE_REVERB_APP_KEY: 'k' })).toMatchObject({ port: 8080, scheme: 'http' })
  })
})
