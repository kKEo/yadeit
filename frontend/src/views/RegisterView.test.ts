import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import i18n from '@/i18n'
import { errorResponse, jsonResponse, makeMe, makeTestRouter } from '@/test/helpers'
import RegisterView from './RegisterView.vue'

describe('RegisterView', () => {
  const fetchMock = vi.fn<typeof fetch>()

  async function open(path = '/register') {
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = makeTestRouter()
    await router.push(path)
    const wrapper = mount(RegisterView, { global: { plugins: [pinia, i18n, router] } })
    return { wrapper, router }
  }

  async function fillAndSubmit(wrapper: Awaited<ReturnType<typeof open>>['wrapper']) {
    await wrapper.find('#name').setValue('Ada')
    if ((wrapper.find('#email').element as HTMLInputElement).value === '') {
      await wrapper.find('#email').setValue('ada@example.com')
    }
    await wrapper.find('#password').setValue('correct horse')
    await wrapper.find('#password-confirmation').setValue('correct horse')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
  }

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows a field error from the API under the matching input, without a duplicate banner', async () => {
    fetchMock.mockImplementation(async (url) =>
      String(url) === '/api/v1/auth/register'
        ? errorResponse(422, 'validation_failed', 'The email has already been taken.', {
            email: ['The email has already been taken.'],
          })
        : new Response(null, { status: 204 }),
    )
    const { wrapper, router } = await open()

    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
    await fillAndSubmit(wrapper)

    expect(wrapper.find('#email-error').text()).toBe('The email has already been taken.')
    expect(wrapper.find('#name-error').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
    expect(router.currentRoute.value.path).toBe('/register')
  })

  it('shows the top-level message when the failure is not about a field', async () => {
    fetchMock.mockImplementation(async (url) =>
      String(url) === '/api/v1/auth/register'
        ? errorResponse(429, 'rate_limited', 'Too Many Attempts.')
        : new Response(null, { status: 204 }),
    )
    const { wrapper } = await open()

    await fillAndSubmit(wrapper)

    expect(wrapper.find('[role="alert"]').text()).toContain('Too Many Attempts.')
    expect(wrapper.find('#email-error').exists()).toBe(false)
  })

  it('prefills the invited email and returns to the invitation after registering', async () => {
    fetchMock.mockImplementation(async (url) => {
      if (String(url) === '/api/v1/me') return jsonResponse({ data: makeMe({ email: 'new@example.com' }) })
      if (String(url) === '/api/v1/auth/register') return jsonResponse({ data: makeMe() }, 201)
      return new Response(null, { status: 204 })
    })
    const { wrapper, router } = await open('/register?redirect=/invitations/tok123&email=new%40example.com')

    expect((wrapper.find('#email').element as HTMLInputElement).value).toBe('new@example.com')
    await fillAndSubmit(wrapper)

    const body = JSON.parse(String(fetchMock.mock.calls[1][1]?.body)) as Record<string, string>
    expect(body).toMatchObject({ name: 'Ada', email: 'new@example.com', password_confirmation: 'correct horse', locale: 'en' })
    expect(router.currentRoute.value.fullPath).toBe('/invitations/tok123')
  })
})
