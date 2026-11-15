import { computed, type Component } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  Bookmark, Bot, Building2, FileText, Megaphone, Palette, Columns3, Copy, FolderKanban, Inbox, Keyboard, LayoutList, Link2, ListTodo, Maximize2, Minimize2, Plus, Settings,
  SignalHigh, Tag, CircleDot, User, UserCheck, Users,
} from '@lucide/vue'
import { useCanEdit } from '@/composables/useCanEdit'
import { useItemLink } from '@/composables/useItemLink'
import { useItemPeek } from '@/composables/useItemPeek'
import { useViewState } from '@/composables/useViewState'
import { BUILT_IN_VIEWS, viewToState } from '@/lib/viewState'
import { useUpdateItem } from '@/queries/itemMutations'
import { useSlug } from '@/queries/keys'
import { useSavedViews } from '@/queries/views'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useUiStore, type ItemProperty } from '@/stores/ui'
import { useWorkspaceStore } from '@/stores/workspace'

export interface Command {
  id: string
  label: string
  /** i18n key of the section the command is listed under. */
  group: string
  icon?: Component
  /** Shortcut to show next to it, in registry notation. */
  shortcut?: string
  run: () => void
}

/** Everything the command palette can do right now, given the page and the focused or open item. */
export function useCommands() {
  const router = useRouter()
  const { t } = useI18n()
  const slug = useSlug()
  const auth = useAuthStore()
  const workspace = useWorkspaceStore()
  const ui = useUiStore()
  const toast = useToastStore()
  const canEdit = useCanEdit()
  const views = useSavedViews()
  const { state, apply, patch } = useViewState()
  const { activeKey, peekKey, isDetailPage, expand, collapse } = useItemPeek()
  const { update } = useUpdateItem()
  const { copyLink } = useItemLink()

  const go = (name: string) => () => void router.push({ name, params: { slug: slug.value } })

  async function copy(text: string, message: string) {
    try {
      await navigator.clipboard.writeText(text)
      toast.success(message)
    } catch {
      toast.error(t('commands.copyFailed'))
    }
  }

  function assignToMe(key: string) {
    if (auth.user) void update(key, { assignee: { id: auth.user.id, name: auth.user.name } })
  }

  const commands = computed<Command[]>(() => {
    const list: Command[] = []
    const key = activeKey.value

    if (key) {
      const group = 'commands.group.item'
      if (canEdit.value) {
        const pickers: [ItemProperty, Component, string][] = [
          ['state', CircleDot, 's'], ['priority', SignalHigh, 'p'], ['assignee', User, 'a'], ['labels', Tag, 'l'],
        ]
        for (const [property, icon, shortcut] of pickers) {
          list.push({
            id: `item-${property}`, group, icon, shortcut,
            label: t(`commands.change.${property}`, { key }), run: () => ui.requestProperty(key, property),
          })
        }
        list.push({ id: 'item-assign-me', group, icon: UserCheck, shortcut: 'i', label: t('commands.assignToMe', { key }), run: () => assignToMe(key) })
      }
      if (peekKey.value) list.push({ id: 'item-expand', group, icon: Maximize2, shortcut: 'f', label: t('commands.expandItem', { key }), run: () => void expand() })
      if (isDetailPage.value) list.push({ id: 'item-collapse', group, icon: Minimize2, shortcut: 'f', label: t('commands.collapseItem', { key }), run: () => void collapse() })
      list.push(
        { id: 'item-copy-key', group, icon: Copy, label: t('commands.copyKey', { key }), run: () => void copy(key, t('commands.copied', { text: key })) },
        { id: 'item-copy-link', group, icon: Link2, label: t('commands.copyLink', { key }), run: () => void copyLink(key) },
      )
    }

    if (canEdit.value) {
      list.push({ id: 'create-item', group: 'commands.group.actions', icon: Plus, shortcut: 'c', label: t('items.create.submit'), run: () => ui.openQuickCapture() })
    }
    list.push(
      { id: 'layout-list', group: 'commands.group.actions', icon: LayoutList, label: t('commands.layout.list'), run: () => void patch({ layout: 'list' }) },
      { id: 'layout-board', group: 'commands.group.actions', icon: Columns3, label: t('commands.layout.board'), run: () => void patch({ layout: 'board' }) },
      { id: 'shortcuts', group: 'commands.group.actions', icon: Keyboard, shortcut: '?', label: t('shortcuts.title'), run: () => { ui.shortcutsHelpOpen = true } },
      { id: 'profile', group: 'commands.group.actions', icon: User, label: t('profile.title'), run: () => { ui.profileOpen = true } },
    )

    list.push(
      { id: 'go-items', group: 'commands.group.navigate', icon: ListTodo, shortcut: 'g i', label: t('nav.items'), run: go('items') },
      { id: 'go-inbox', group: 'commands.group.navigate', icon: Inbox, shortcut: 'g n', label: t('nav.inbox'), run: go('inbox') },
      { id: 'go-projects', group: 'commands.group.navigate', icon: FolderKanban, shortcut: 'g p', label: t('nav.projects'), run: go('projects') },
      { id: 'go-members', group: 'commands.group.navigate', icon: Users, shortcut: 'g m', label: t('nav.members'), run: go('workspace-members') },
      { id: 'show-design', group: 'commands.group.navigate', icon: Palette, label: t('commands.designWork'), run: () => void router.push({ name: 'items', params: { slug: slug.value }, query: { kind: 'design', open: '1' } }) },
      { id: 'go-feedback', group: 'commands.group.navigate', icon: Megaphone, label: t('nav.feedback'), run: go('feedback') },
      { id: 'go-docs', group: 'commands.group.navigate', icon: FileText, label: t('nav.docs'), run: go('docs') },
      { id: 'go-agents', group: 'commands.group.navigate', icon: Bot, label: t('nav.agents'), run: go('agents') },
      { id: 'go-settings', group: 'commands.group.navigate', icon: Settings, label: t('nav.settings'), run: go('workspace-settings') },
    )

    for (const view of BUILT_IN_VIEWS) {
      list.push({
        id: `view-${view.id}`, group: 'commands.group.views', icon: Bookmark,
        label: t('commands.applyView', { view: t(`views.builtIn.${view.id}`) }), run: () => void apply(view.state, { view: view.id }),
      })
    }
    for (const view of views.data.value ?? []) {
      list.push({
        id: `view-${view.id}`, group: 'commands.group.views', icon: Bookmark,
        label: t('commands.applyView', { view: view.name }), run: () => void apply(viewToState(view), { view: String(view.id) }),
      })
    }

    for (const other of workspace.list) {
      if (other.slug === slug.value) continue
      list.push({
        id: `workspace-${other.slug}`, group: 'commands.group.workspaces', icon: Building2,
        label: t('commands.switchWorkspace', { workspace: other.name }),
        run: () => void router.push({ name: 'items', params: { slug: other.slug } }),
      })
    }
    return list
  })

  return { commands, state, assignToMe }
}
