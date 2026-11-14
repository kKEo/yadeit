import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as api from '@/api/client'
import { ApiError } from '@/api/client'
import type { FieldErrors, User } from '@/api/types'
import i18n from '@/i18n'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const isAuthenticated = computed(() => user.value !== null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const fieldErrors = ref<FieldErrors>({})

  let initial: Promise<void> | null = null

  /** Loads the current session user. A 401 simply means "signed out". */
  async function fetchUser(): Promise<void> {
    try {
      user.value = await api.getMe()
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

  async function login(email: string, password: string): Promise<boolean> {
    loading.value = true
    error.value = null
    fieldErrors.value = {}
    try {
      await api.login(email, password)
      user.value = await api.getMe()
      return true
    } catch (err: unknown) {
      user.value = null
      error.value = err instanceof Error ? err.message : i18n.global.t('errors.login')
      if (err instanceof ApiError) fieldErrors.value = err.fields
      return false
    } finally {
      loading.value = false
    }
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

  return {
    user, isAuthenticated, loading, error, fieldErrors,
    fetchUser, ensureInitialized, login, logout, reset,
  }
})
