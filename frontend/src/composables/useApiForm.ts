import { ref } from 'vue'
import { ApiError } from '@/api/client'
import type { FieldErrors } from '@/api/types'
import i18n from '@/i18n'

/**
 * The message to show above a form: nothing when the failure is already
 * explained under one of the inputs the form displays.
 */
export function topLevelError(
  message: string | null,
  fields: FieldErrors,
  shownFields: readonly string[],
): string | null {
  return shownFields.some((name) => fields[name]?.length) ? null : message
}

/** Pending flag and API error state for one form or action. */
export function useApiForm(shownFields: readonly string[] = []) {
  const pending = ref(false)
  const error = ref<string | null>(null)
  const fieldErrors = ref<FieldErrors>({})

  function clear() {
    error.value = null
    fieldErrors.value = {}
  }

  /** Runs the request; resolves to whether it succeeded. Ignores calls while one is pending. */
  async function submit(action: () => Promise<void>): Promise<boolean> {
    if (pending.value) return false
    pending.value = true
    clear()
    try {
      await action()
      return true
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : i18n.global.t('errors.generic')
      fieldErrors.value = err instanceof ApiError ? err.fields : {}
      error.value = topLevelError(message, fieldErrors.value, shownFields)
      return false
    } finally {
      pending.value = false
    }
  }

  return { pending, error, fieldErrors, submit, clear }
}
