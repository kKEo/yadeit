import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as api from '@/api/client'
import { ApiError } from '@/api/client'
import type {
  CreateInvitationRequest,
  CreateWorkspaceRequest,
  Invitation,
  Member,
  UpdateWorkspaceRequest,
  Workspace,
  WorkspaceRole,
} from '@/api/types'
import i18n from '@/i18n'
import { WORKSPACE_ROLES } from '@/lib/roles'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'

/**
 * Workspaces of the signed-in user and the data of the one in the URL.
 * Mutations throw on failure; forms report the error (see `useApiForm`).
 */
export const useWorkspaceStore = defineStore('workspace', () => {
  const auth = useAuthStore()

  const list = computed<Workspace[]>(() => auth.user?.workspaces ?? [])
  const currentSlug = computed(() => {
    const slug = router.currentRoute.value.params.slug
    return typeof slug === 'string' ? slug : null
  })
  const current = computed(() => list.value.find((w) => w.slug === currentSlug.value) ?? null)
  const role = computed<WorkspaceRole | null>(() => current.value?.role ?? null)

  const loading = ref(false)
  /** The API answered 404 for the slug in the URL: unknown workspace or not a member. */
  const notFound = ref(false)
  const loadError = ref<string | null>(null)
  const members = ref<Member[]>([])
  const invitations = ref<Invitation[]>([])

  let loadedSlug: string | null = null

  function requireSlug(): string {
    if (!currentSlug.value) throw new Error(i18n.global.t('errors.generic'))
    return currentSlug.value
  }

  /** Adds or replaces (by id) one workspace, keeping the list sorted by name like the API. */
  function upsert(workspace: Workspace) {
    const others = list.value.filter((w) => w.id !== workspace.id)
    auth.setWorkspaces([...others, workspace].sort((a, b) => a.name.localeCompare(b.name)))
  }

  function drop(slug: string) {
    auth.setWorkspaces(list.value.filter((w) => w.slug !== slug))
  }

  /** Confirms with the API that the workspace in the URL exists and the user belongs to it. */
  async function load(slug: string): Promise<void> {
    if (slug !== loadedSlug) {
      members.value = []
      invitations.value = []
    }
    loadedSlug = slug
    loading.value = true
    notFound.value = false
    loadError.value = null
    try {
      const workspace = await api.getWorkspace(slug)
      if (slug === loadedSlug) upsert(workspace)
    } catch (err: unknown) {
      if (slug !== loadedSlug) return
      if (err instanceof ApiError && err.status === 404) {
        notFound.value = true
        drop(slug)
      } else {
        loadError.value = err instanceof Error ? err.message : i18n.global.t('errors.generic')
      }
    } finally {
      if (slug === loadedSlug) loading.value = false
    }
  }

  async function create(req: CreateWorkspaceRequest): Promise<Workspace> {
    const workspace = await api.createWorkspace(req)
    upsert(workspace)
    return workspace
  }

  /** Renames the current workspace and/or changes its slug. */
  async function update(req: UpdateWorkspaceRequest): Promise<Workspace> {
    const workspace = await api.updateWorkspace(requireSlug(), req)
    // Keep `load()` from treating the new slug as a different workspace.
    loadedSlug = workspace.slug
    upsert(workspace)
    return workspace
  }

  async function remove(): Promise<void> {
    const slug = requireSlug()
    await api.deleteWorkspace(slug)
    drop(slug)
  }

  // ── Members ────────────────────────────────────────────────────────────────

  function sortMembers(items: Member[]): Member[] {
    const rank = (m: Member) => WORKSPACE_ROLES.indexOf(m.role)
    return [...items].sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name))
  }

  async function fetchMembers(): Promise<void> {
    members.value = await api.listMembers(requireSlug())
  }

  async function changeMemberRole(userId: number, newRole: WorkspaceRole): Promise<void> {
    const member = await api.updateMemberRole(requireSlug(), userId, newRole)
    members.value = sortMembers(members.value.map((m) => (m.id === userId ? member : m)))
    if (userId === auth.user?.id && current.value) upsert({ ...current.value, role: member.role })
  }

  async function removeMember(userId: number): Promise<void> {
    await api.removeMember(requireSlug(), userId)
    members.value = members.value.filter((m) => m.id !== userId)
  }

  /** The signed-in user leaves the current workspace. */
  async function leave(): Promise<void> {
    const slug = requireSlug()
    if (!auth.user) throw new Error(i18n.global.t('errors.generic'))
    await api.removeMember(slug, auth.user.id)
    drop(slug)
  }

  // ── Invitations ────────────────────────────────────────────────────────────

  async function fetchInvitations(): Promise<void> {
    invitations.value = await api.listInvitations(requireSlug())
  }

  async function invite(req: CreateInvitationRequest): Promise<void> {
    const invitation = await api.createInvitation(requireSlug(), req)
    // Inviting an address again replaces its earlier open invitation.
    invitations.value = [invitation, ...invitations.value.filter((i) => i.email !== invitation.email)]
  }

  async function withdrawInvitation(id: number): Promise<void> {
    await api.deleteInvitation(requireSlug(), id)
    invitations.value = invitations.value.filter((i) => i.id !== id)
  }

  return {
    list, currentSlug, current, role, loading, notFound, loadError, members, invitations,
    requireSlug, load, create, update, remove,
    fetchMembers, changeMemberRole, removeMember, leave,
    fetchInvitations, invite, withdrawInvitation,
  }
})
