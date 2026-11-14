import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ItemProperty = 'state' | 'priority' | 'assignee' | 'labels' | 'kind'

/** Which overlays are open and which item the keyboard is pointing at. */
export const useUiStore = defineStore('ui', () => {
  /** Row or card the J/K cursor is on. */
  const focusedKey = ref<string | null>(null)
  const quickCaptureOpen = ref(false)
  /** Key of the item a quick capture should become a sub-item of. */
  const captureParent = ref<string | null>(null)
  const paletteOpen = ref(false)
  const shortcutsHelpOpen = ref(false)
  const profileOpen = ref(false)
  /** A property picker asked for from the keyboard or the command palette. */
  const propertyRequest = ref<{ key: string; property: ItemProperty } | null>(null)

  function requestProperty(key: string, property: ItemProperty) {
    paletteOpen.value = false
    propertyRequest.value = { key, property }
  }

  function openQuickCapture(parent: string | null = null) {
    paletteOpen.value = false
    captureParent.value = parent
    quickCaptureOpen.value = true
  }

  return {
    focusedKey, quickCaptureOpen, captureParent, paletteOpen, shortcutsHelpOpen, profileOpen, propertyRequest,
    requestProperty, openQuickCapture,
  }
})
