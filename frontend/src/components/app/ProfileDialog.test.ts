import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import i18n from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { errorResponse, makeMe, makeTestRouter } from '@/test/helpers'
import ProfileDialog from './ProfileDialog.vue'

describe('ProfileDialog: your data', () => {
  const fetchMock = vi.fn<typeof fetch>()

  async function open() {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = makeMe()
    const router = makeTestRouter()
    await router.push('/w/acme/items')
    mount(ProfileDialog, { global: { plugins: [pinia, i18n, router] }, attachTo: document.body })
    useUiStore().profileOpen = true
    await flushPromises()
    return router
  }

  const button = (label: string) => [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === label) as HTMLButtonElement | undefined

  async function typePassword(value: string) {
    const input = document.body.querySelector('#profile-delete-password') as HTMLInputElement
    input.value = value
    input.dispatchEvent(new Event('input'))
    await flushPromises()
  }

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
  })

  it('links to the download of the personal data', async () => {
    await open()
    const link = [...document.body.querySelectorAll('a')].find((a) => a.textContent?.trim() === 'Download my data')

    expect(link?.getAttribute('href')).toBe('/api/v1/me/export')
    expect(link?.hasAttribute('download')).toBe(true)
  })

  it('deletes the account only with the password, and shows why it was refused', async () => {
    fetchMock.mockResolvedValueOnce(errorResponse(409, 'sole_owner', 'You are the only owner of: Acme.'))
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }))
    const router = await open()

    expect(button('Delete my account')).toBeUndefined()
    button('Delete my account…')!.click()
    await flushPromises()
    expect(button('Delete my account')!.disabled).toBe(true)

    await typePassword('secret-password')
    button('Delete my account')!.click()
    await flushPromises()
    expect(document.body.textContent).toContain('You are the only owner of: Acme.')
    expect(useAuthStore().user).not.toBeNull()

    button('Delete my account')!.click()
    await flushPromises()
    const [url, init] = fetchMock.mock.calls[1]
    expect(String(url)).toContain('/api/v1/me')
    expect(init?.method).toBe('DELETE')
    expect(JSON.parse(String(init?.body))).toEqual({ password: 'secret-password' })
    expect(useAuthStore().user).toBeNull()
    expect(router.currentRoute.value.name).toBe('home')
  })
})
