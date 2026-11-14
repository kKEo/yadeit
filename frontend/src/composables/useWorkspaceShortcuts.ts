import { useRouter } from 'vue-router'
import { useCanEdit } from '@/composables/useCanEdit'
import { useCommands } from '@/composables/useCommands'
import { useItemPeek } from '@/composables/useItemPeek'
import { useShortcuts, type ShortcutBinding } from '@/composables/useShortcuts'
import { useSlug } from '@/queries/keys'
import { useUiStore, type ItemProperty } from '@/stores/ui'

/** Shortcuts that work on every workspace page: create, navigation, the palette, and the focused or open item. */
export function useWorkspaceShortcuts() {
  const router = useRouter()
  const slug = useSlug()
  const ui = useUiStore()
  const canEdit = useCanEdit()
  const { activeKey, peekKey, close } = useItemPeek()
  const { assignToMe } = useCommands()

  const go = (name: string) => () => void router.push({ name, params: { slug: slug.value } })
  const onItem = () => canEdit.value && activeKey.value !== null

  const property = (keys: string, name: ItemProperty): ShortcutBinding => ({
    keys, group: 'shortcuts.group.item', description: `shortcuts.item.${name}`, when: onItem,
    handler: () => { if (activeKey.value) ui.requestProperty(activeKey.value, name) },
  })

  useShortcuts([
    { keys: 'mod+k', group: 'shortcuts.group.general', description: 'shortcuts.palette', global: true, handler: () => { ui.paletteOpen = !ui.paletteOpen } },
    { keys: '?', group: 'shortcuts.group.general', description: 'shortcuts.help', handler: () => { ui.shortcutsHelpOpen = true } },
    { keys: 'c', group: 'shortcuts.group.general', description: 'shortcuts.create', when: () => canEdit.value, handler: () => ui.openQuickCapture() },
    { keys: 'escape', group: 'shortcuts.group.general', description: 'shortcuts.close', when: () => peekKey.value !== null, handler: () => void close() },
    { keys: 'g i', group: 'shortcuts.group.navigate', description: 'shortcuts.go.items', handler: go('items') },
    { keys: 'g p', group: 'shortcuts.group.navigate', description: 'shortcuts.go.projects', handler: go('projects') },
    { keys: 'g m', group: 'shortcuts.group.navigate', description: 'shortcuts.go.members', handler: go('workspace-members') },
    { keys: 'g n', group: 'shortcuts.group.navigate', description: 'shortcuts.go.inbox', handler: go('inbox') },
    property('s', 'state'),
    property('p', 'priority'),
    property('a', 'assignee'),
    property('l', 'labels'),
    {
      keys: 'i', group: 'shortcuts.group.item', description: 'shortcuts.item.assignToMe', when: onItem,
      handler: () => { if (activeKey.value) assignToMe(activeKey.value) },
    },
  ])
}
