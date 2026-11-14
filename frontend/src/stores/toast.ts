import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ToastKind = 'info' | 'success' | 'error'
export interface Toast { id: number; kind: ToastKind; message: string }

const LIFETIME_MS = 5000

/** Short, self-dismissing messages for things that happen away from a form (background saves, conflicts). */
export const useToastStore = defineStore('toast', () => {
  const toasts = ref<Toast[]>([])
  let nextId = 1

  function dismiss(id: number) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }

  function show(message: string, kind: ToastKind = 'info'): number {
    const id = nextId++
    toasts.value = [...toasts.value, { id, kind, message }]
    setTimeout(() => dismiss(id), LIFETIME_MS)
    return id
  }

  const error = (message: string) => show(message, 'error')
  const success = (message: string) => show(message, 'success')

  return { toasts, show, error, success, dismiss }
})
