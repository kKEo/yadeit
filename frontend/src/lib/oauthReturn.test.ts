import { beforeEach, describe, expect, it, vi } from 'vitest'
import { makeTestRouter } from '@/test/helpers'
import { followRedirect, isOAuthReturn } from './redirect'

const left = vi.hoisted(() => [] as string[])
vi.mock('@/lib/navigate', () => ({ leaveTo: (url: string) => { left.push(url) }, saveFile: () => undefined }))

describe('returning to an app that asked for access', () => {
  beforeEach(() => { left.length = 0 })

  it('recognises only the consent screen as a way out of the app', () => {
    expect(isOAuthReturn('/oauth/authorize?client_id=1')).toBe(true)
    expect(isOAuthReturn('/oauth/token')).toBe(false)
    expect(isOAuthReturn('/w/acme/items')).toBe(false)
  })

  it('leaves for the consent screen after signing in, and stays inside the app otherwise', async () => {
    const router = makeTestRouter()

    await followRedirect(router, { redirect: '/oauth/authorize?client_id=1&state=x' })
    expect(left).toEqual(['/oauth/authorize?client_id=1&state=x'])

    await followRedirect(router, { redirect: '/w/acme/items' })
    expect(router.currentRoute.value.fullPath).toBe('/w/acme/items')

    // Another site is never a target, whatever the link says.
    await followRedirect(router, { redirect: 'https://evil.example/oauth/authorize?x=1' })
    await followRedirect(router, { redirect: '//evil.example/oauth/authorize?x=1' })
    expect(left).toHaveLength(1)
  })
})
