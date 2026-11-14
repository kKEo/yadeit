import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createShortcutRegistry, isTypingTarget, keyCaps, type ShortcutBinding } from './useShortcuts'

function press(target: EventTarget, key: string, init: KeyboardEventInit = {}): KeyboardEvent {
  const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init })
  target.dispatchEvent(event)
  return event
}

describe('shortcut registry', () => {
  const registry = createShortcutRegistry(1000)
  const listener = (event: Event) => registry.handle(event as KeyboardEvent)
  let off: (() => void)[] = []

  const bind = (keys: string, extra: Partial<ShortcutBinding> = {}) => {
    const handler = vi.fn()
    off.push(registry.register({ keys, description: `test.${keys}`, group: 'test.group', handler, ...extra }))
    return handler
  }

  beforeEach(() => {
    vi.useFakeTimers()
    document.body.innerHTML = '<input id="text" /><textarea id="area"></textarea><div id="editable" contenteditable="true"></div><button id="button"></button><div role="dialog"><button id="in-dialog"></button></div>'
    window.addEventListener('keydown', listener)
  })

  afterEach(() => {
    window.removeEventListener('keydown', listener)
    off.forEach((remove) => remove())
    off = []
    registry.clearPrefix()
    vi.useRealTimers()
  })

  it('runs a single-key binding and prevents the default action', () => {
    const create = bind('c')
    const event = press(document.body, 'c')
    expect(create).toHaveBeenCalledTimes(1)
    expect(event.defaultPrevented).toBe(true)
  })

  it('ignores keystrokes while typing in inputs, textareas and contenteditable', () => {
    const create = bind('c')
    for (const id of ['text', 'area', 'editable']) press(document.getElementById(id)!, 'c')
    expect(create).not.toHaveBeenCalled()

    press(document.getElementById('button')!, 'c')
    expect(create).toHaveBeenCalledTimes(1)
    expect(isTypingTarget(document.getElementById('editable'))).toBe(true)
    expect(isTypingTarget(document.getElementById('button'))).toBe(false)
  })

  it('stays quiet inside dialogs, except for global bindings', () => {
    const next = bind('j')
    const palette = bind('mod+k', { global: true })
    press(document.getElementById('in-dialog')!, 'j')
    press(document.getElementById('text')!, 'k', { metaKey: true })
    press(document.getElementById('in-dialog')!, 'k', { ctrlKey: true })
    expect(next).not.toHaveBeenCalled()
    expect(palette).toHaveBeenCalledTimes(2)
  })

  it('does not mistake Ctrl/Cmd combinations for plain keys', () => {
    const create = bind('c')
    press(document.body, 'c', { metaKey: true })
    press(document.body, 'c', { ctrlKey: true })
    expect(create).not.toHaveBeenCalled()
  })

  it('supports two-key sequences', () => {
    const goItems = bind('g i')
    const goProjects = bind('g p')
    const assign = bind('i')

    press(document.body, 'g')
    press(document.body, 'i')
    expect(goItems).toHaveBeenCalledTimes(1)
    expect(assign).not.toHaveBeenCalled()

    press(document.body, 'g')
    press(document.body, 'p')
    expect(goProjects).toHaveBeenCalledTimes(1)
  })

  it('forgets the first key of a sequence after the timeout', () => {
    const goItems = bind('g i')
    const assign = bind('i')

    press(document.body, 'g')
    vi.advanceTimersByTime(1001)
    press(document.body, 'i')

    expect(goItems).not.toHaveBeenCalled()
    expect(assign).toHaveBeenCalledTimes(1)
  })

  it('skips bindings whose condition is false and lets the newest binding win', () => {
    let enabled = false
    const older = bind('enter')
    const newer = bind('enter', { when: () => enabled })

    press(document.body, 'Enter')
    expect(older).toHaveBeenCalledTimes(1)

    enabled = true
    press(document.body, 'Enter')
    expect(newer).toHaveBeenCalledTimes(1)
    expect(older).toHaveBeenCalledTimes(1)
  })

  it('lists its bindings for the help dialog and forgets removed ones', () => {
    bind('c')
    bind('g i')
    bind('arrowdown', { hidden: true })
    const remove = registry.register({ keys: '?', description: 'test.help', group: 'test.group', handler: () => undefined })

    expect(registry.list()).toEqual([
      { keys: 'c', description: 'test.c', group: 'test.group' },
      { keys: 'g i', description: 'test.g i', group: 'test.group' },
      { keys: '?', description: 'test.help', group: 'test.group' },
    ])

    remove()
    expect(registry.list().map((binding) => binding.keys)).toEqual(['c', 'g i'])
  })

  it('names space and renders key caps', () => {
    const open = bind('space')
    press(document.body, ' ')
    expect(open).toHaveBeenCalledTimes(1)
    expect(keyCaps('g i', false)).toEqual([['G'], ['I']])
    expect(keyCaps('mod+k', true)).toEqual([['⌘', 'K']])
    expect(keyCaps('mod+enter', false)).toEqual([['Ctrl', '↵']])
  })
})
