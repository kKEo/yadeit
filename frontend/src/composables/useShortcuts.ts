import { onScopeDispose } from 'vue'

export interface ShortcutBinding {
  /**
   * One key ("c", "/", "?", "enter", "space", "escape", "arrowdown"), a modifier
   * combination ("mod+k": Cmd on macOS, Ctrl elsewhere) or a two-key sequence ("g i").
   */
  keys: string
  /** i18n key of what the shortcut does; shown in the help dialog. */
  description: string
  /** i18n key of the help section. */
  group: string
  handler: (event: KeyboardEvent) => void
  /** Also fires while typing in a field or with a dialog open (for example the command palette). */
  global?: boolean
  /** Checked at key press; a binding whose `when` is false is skipped. */
  when?: () => boolean
  /** Left out of the help dialog (aliases such as the arrow keys next to J/K). */
  hidden?: boolean
}

export interface ShortcutInfo { keys: string; description: string; group: string }

const SEQUENCE_TIMEOUT_MS = 1000
const OVERLAY_SELECTOR = '[role="dialog"], [role="menu"], [role="listbox"]'

/** Whether keystrokes on this element are text entry and must not trigger shortcuts. */
export function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  if (target.isContentEditable || target.getAttribute('contenteditable') === 'true') return true
  if (target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement) return true
  if (target instanceof HTMLInputElement) {
    return !['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'file'].includes(target.type)
  }
  return false
}

function insideOverlay(target: EventTarget | null): boolean {
  return target instanceof Element && target.closest(OVERLAY_SELECTOR) !== null
}

/** The name a key press is matched by, or null for a bare modifier. */
export function keyName(event: KeyboardEvent): string | null {
  const key = event.key
  if (!key || ['Shift', 'Control', 'Alt', 'Meta'].includes(key)) return null
  const base = key === ' ' ? 'space' : key.toLowerCase()
  return event.metaKey || event.ctrlKey ? `mod+${base}` : base
}

export function createShortcutRegistry(sequenceTimeoutMs = SEQUENCE_TIMEOUT_MS) {
  const bindings: ShortcutBinding[] = []
  let prefix: string | null = null
  let timer: ReturnType<typeof setTimeout> | null = null

  function clearPrefix() {
    prefix = null
    if (timer) clearTimeout(timer)
    timer = null
  }

  /** Adds bindings; returns a function that removes exactly those. */
  function register(added: ShortcutBinding | ShortcutBinding[]): () => void {
    const list = (Array.isArray(added) ? added : [added]).map((b) => ({ ...b, keys: b.keys.trim().toLowerCase() }))
    bindings.push(...list)
    return () => {
      for (const binding of list) {
        const index = bindings.indexOf(binding)
        if (index !== -1) bindings.splice(index, 1)
      }
    }
  }

  /** The newest matching binding wins, so a page can override a layout-level shortcut. */
  function find(keys: string, restricted: boolean): ShortcutBinding | undefined {
    for (let i = bindings.length - 1; i >= 0; i--) {
      const binding = bindings[i]
      if (binding.keys !== keys || (restricted && !binding.global)) continue
      if (binding.when && !binding.when()) continue
      return binding
    }
    return undefined
  }

  /** Returns whether a binding ran (or a sequence started). */
  function handle(event: KeyboardEvent): boolean {
    if (event.defaultPrevented || event.isComposing) return false
    const name = keyName(event)
    if (!name) return false

    const restricted = isTypingTarget(event.target) || insideOverlay(event.target)
    // Alt combinations type characters on many layouts; never treat them as shortcuts.
    if (event.altKey) return false

    if (prefix !== null) {
      const sequence = `${prefix} ${name}`
      clearPrefix()
      const binding = find(sequence, restricted)
      if (binding) {
        event.preventDefault()
        binding.handler(event)
        return true
      }
    }

    const binding = find(name, restricted)
    if (binding) {
      event.preventDefault()
      binding.handler(event)
      return true
    }

    if (!restricted && bindings.some((b) => b.keys.startsWith(`${name} `))) {
      prefix = name
      timer = setTimeout(clearPrefix, sequenceTimeoutMs)
      return true
    }
    return false
  }

  /** Bindings for the help dialog: one line per key, hidden aliases and inactive ones left out. */
  function list(): ShortcutInfo[] {
    const seen = new Set<string>()
    const result: ShortcutInfo[] = []
    for (let i = bindings.length - 1; i >= 0; i--) {
      const { keys, description, group, hidden } = bindings[i]
      if (hidden || seen.has(keys)) continue
      seen.add(keys)
      result.unshift({ keys, description, group })
    }
    return result
  }

  return { register, handle, list, clearPrefix }
}

export type ShortcutRegistry = ReturnType<typeof createShortcutRegistry>

/** The app's registry; `App.vue` feeds it the window's keydown events. */
export const shortcuts = createShortcutRegistry()

/** Registers bindings for as long as the calling component (or effect scope) lives. */
export function useShortcuts(bindings: ShortcutBinding[], registry: ShortcutRegistry = shortcuts) {
  onScopeDispose(registry.register(bindings))
}

/** Splits "g i" / "mod+k" into the key caps to display. */
export function keyCaps(keys: string, isMac = /mac|iphone|ipad/i.test(globalThis.navigator?.platform ?? '')): string[][] {
  const labels: Record<string, string> = {
    mod: isMac ? '⌘' : 'Ctrl', enter: '↵', escape: 'Esc', space: 'Space', backspace: '⌫',
    arrowup: '↑', arrowdown: '↓', arrowleft: '←', arrowright: '→',
  }
  return keys.split(' ').map((step) => step.split('+').map((part) => labels[part] ?? part.toUpperCase()))
}
