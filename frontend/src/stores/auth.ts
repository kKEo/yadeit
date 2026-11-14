import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as api from '@/api/client'
import { ApiError } from '@/api/client'
import type { FieldErrors, Me, RegisterRequest, UpdateMeRequest, Workspace } from '@/api/types'
import i18n, { isSupportedLocale, setLocale } from '@/i18n'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<Me | null>(null)
  const isAuthenticated = computed(() => user.value !== null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const fieldErrors = ref<FieldErrors>({})

  let initial: Promise<void> | null = null

  /** The account's saved language wins over the browser's remembered choice. */
  function setUser(me: Me) {
    user.value = me
    if (isSupportedLocale(me.locale)) setLocale(me.locale)
  }

  /** Reloads `me` (profile and workspace list); throws when the request fails. */
  async function refresh(): Promise<void> {
    setUser(await api.getMe())
  }

  /** Loads the current session user. A 401 simply means "signed out". */
  async function fetchUser(): Promise<void> {
    try {
      await refresh()
    } catch (err: unknown) {
      user.value = null
      if (!(err instanceof ApiError && err.status === 401)) {
        error.value = err instanceof Error ? err.message : i18n.global.t('errors.generic')
      }
    }
  }

  /** Runs `fetchUser()` once; later calls reuse the same promise (router guard). */
  function ensureInitialized(): Promise<void> {
    initial ??= fetchUser()
    return initial
  }

  /** Runs a sign-in style request, then loads `me`; reports failures through `error` / `fieldErrors`. */
  async function signIn(request: () => Promise<void>, fallbackKey: string): Promise<boolean> {
    loading.value = true
    clearError()
    try {
      await request()
      await refresh()
      return true
    } catch (err: unknown) {
      user.value = null
      error.value = err instanceof Error ? err.message : i18n.global.t(fallbackKey)
      if (err instanceof ApiError) fieldErrors.value = err.fields
      return false
    } finally {
      loading.value = false
    }
  }

  function login(email: string, password: string): Promise<boolean> {
    return signIn(() => api.login(email, password), 'errors.login')
  }

  function register(req: RegisterRequest): Promise<boolean> {
    return signIn(() => api.register(req), 'errors.register')
  }

  /** Saves profile changes (name, language); throws when the request fails. */
  async function updateProfile(req: UpdateMeRequest): Promise<void> {
    setUser(await api.updateMe(req))
  }

  /** Replaces the workspace list after a local change (create, rename, delete). */
  function setWorkspaces(workspaces: Workspace[]) {
    if (user.value) user.value = { ...user.value, workspaces }
  }

  async function logout(): Promise<void> {
    try {
      await api.logout()
    } catch {
      // the local session is dropped regardless
    } finally {
      user.value = null
    }
  }

  /** Drops local session state without calling the API (e.g. after a 401). */
  function reset() {
    user.value = null
  }

  function clearError() {
    error.value = null
    fieldErrors.value = {}
  }

  return {
    user, isAuthenticated, loading, error, fieldErrors,
    fetchUser, ensureInitialized, refresh, login, register, updateProfile, setWorkspaces,
    logout, reset, clearError,
  }
})
