<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Bot, Check, Copy, KeyRound, Trash2 } from '@lucide/vue'
import * as api from '@/api/agents'
import { listProjects } from '@/api/projects'
import type { Agent, AgentConnection, AgentToken, NewAgentToken, Project } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { formatDateTime } from '@/lib/format'
import { roleAtLeast } from '@/lib/roles'
import { useAuthStore } from '@/stores/auth'
import { useWorkspaceStore } from '@/stores/workspace'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import EmptyState from '@/components/app/EmptyState.vue'
import FormField from '@/components/app/FormField.vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardContent from '@/components/ui/CardContent.vue'
import Checkbox from '@/components/ui/Checkbox.vue'
import Dialog from '@/components/ui/Dialog.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

/** Agents of the workspace, and the access tokens of the ones the viewer may manage. */
const route = useRoute()
const auth = useAuthStore()
const workspace = useWorkspaceStore()
const { t, locale } = useI18n()

const slug = computed(() => String(route.params.slug))
const list = useApiForm()
const createForm = useApiForm(['name', 'description'])
const tokenForm = useApiForm(['name', 'access', 'projects', 'expires_in_days'])

const loading = ref(true)
const agents = ref<Agent[]>([])
const projects = ref<Project[]>([])
const name = ref('')
const description = ref('')

/** The agent whose tokens are open, with its tokens once loaded. */
const openId = ref<number | null>(null)
const tokens = ref<AgentToken[]>([])
const tokensLoading = ref(false)
const connections = ref<AgentConnection[]>([])
const draft = reactive({ name: '', access: 'write' as 'read' | 'write', projects: [] as string[], expiry: '90' })

const secret = ref<NewAgentToken | null>(null)
const copied = ref(false)
const removing = ref<Agent | null>(null)

const canCreate = computed(() => roleAtLeast(workspace.role, 'member'))
const removeOpen = computed({
  get: () => removing.value !== null,
  set: (open) => { if (!open) removing.value = null },
})
const secretOpen = computed({
  get: () => secret.value !== null,
  set: (open) => { if (!open) closeSecret() },
})
const accessOptions = computed(() => [
  { value: 'write', label: t('agents.tokens.write') },
  { value: 'read', label: t('agents.tokens.read') },
])
const expiryOptions = computed(() => [
  ...[30, 90, 365].map((n) => ({ value: String(n), label: t('agents.tokens.days', { n }) })),
  { value: 'never', label: t('agents.tokens.never') },
])

function canManage(agent: Agent): boolean {
  return roleAtLeast(workspace.role, 'admin') || (agent.owner?.id === auth.user?.id && canCreate.value)
}

onMounted(async () => {
  await list.submit(async () => {
    [agents.value, projects.value] = await Promise.all([api.listAgents(slug.value), listProjects(slug.value)])
  })
  loading.value = false
})

async function create() {
  if (name.value.trim() === '') return
  const ok = await createForm.submit(async () => {
    const agent = await api.createAgent(slug.value, { name: name.value.trim(), description: description.value.trim() || null })
    agents.value = [...agents.value, agent].sort((a, b) => a.name.localeCompare(b.name))
    await toggle(agent, true)
  })
  if (ok) {
    name.value = ''
    description.value = ''
  }
}

async function toggle(agent: Agent, forceOpen = false) {
  if (openId.value === agent.id && !forceOpen) {
    openId.value = null
    return
  }
  openId.value = agent.id
  tokens.value = []
  connections.value = []
  tokenForm.clear()
  Object.assign(draft, { name: '', access: 'write', projects: [], expiry: '90' })
  tokensLoading.value = true
  try {
    [tokens.value, connections.value] = await Promise.all([api.listAgentTokens(slug.value, agent.id), api.listAgentConnections(slug.value, agent.id)])
  } catch (err: unknown) {
    list.error.value = err instanceof Error ? err.message : t('errors.generic')
  } finally {
    tokensLoading.value = false
  }
}

async function disconnect(agent: Agent, connection: AgentConnection) {
  const ok = await list.submit(() => api.disconnectAgentApp(slug.value, agent.id, connection.id))
  if (ok) connections.value = connections.value.filter((c) => c.id !== connection.id)
}

function toggleProject(key: string, checked: boolean) {
  draft.projects = checked ? [...draft.projects, key] : draft.projects.filter((k) => k !== key)
}

function setTokenCount(agentId: number, count: number) {
  agents.value = agents.value.map((a) => (a.id === agentId ? { ...a, token_count: count } : a))
}

async function createToken(agent: Agent) {
  if (draft.name.trim() === '') return
  await tokenForm.submit(async () => {
    const created = await api.createAgentToken(slug.value, agent.id, {
      name: draft.name.trim(),
      access: draft.access,
      projects: draft.projects,
      expires_in_days: draft.expiry === 'never' ? null : Number(draft.expiry),
    })
    const { token: _secret, ...row } = created
    tokens.value = [row, ...tokens.value]
    setTokenCount(agent.id, tokens.value.length)
    secret.value = created
    draft.name = ''
  })
}

async function revoke(agent: Agent, token: AgentToken) {
  await list.submit(async () => {
    await api.revokeAgentToken(slug.value, agent.id, token.id)
    tokens.value = tokens.value.filter((row) => row.id !== token.id)
    setTokenCount(agent.id, tokens.value.length)
  })
}

async function confirmRemove() {
  const agent = removing.value
  if (!agent) return
  await list.submit(async () => {
    await api.deleteAgent(slug.value, agent.id)
    agents.value = agents.value.filter((a) => a.id !== agent.id)
    if (openId.value === agent.id) openId.value = null
  })
  removing.value = null
}

async function copySecret() {
  if (!secret.value) return
  try {
    await navigator.clipboard.writeText(secret.value.token)
    copied.value = true
  } catch {
    // No clipboard access: the token stays selectable in the field.
    copied.value = false
  }
}

function closeSecret() {
  secret.value = null
  copied.value = false
}
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight">{{ t('agents.title') }}</h1>
      <p class="max-w-2xl text-sm text-muted-foreground">{{ t('agents.subtitle') }}</p>
    </div>

    <Alert v-if="list.error.value" variant="destructive" :title="list.error.value" />

    <form v-if="canCreate" novalidate class="space-y-3 rounded-lg border p-4" @submit.prevent="create">
      <Alert v-if="createForm.error.value" variant="destructive" :title="createForm.error.value" />
      <div class="grid gap-4 sm:grid-cols-[14rem_1fr_auto] sm:items-start">
        <FormField id="agent-name" :label="t('agents.create.name')" :errors="createForm.fieldErrors.value.name">
          <Input id="agent-name" v-model="name" name="name" autocomplete="off" maxlength="80" :placeholder="t('agents.create.namePlaceholder')" />
        </FormField>
        <FormField id="agent-description" :label="t('agents.create.description')" :errors="createForm.fieldErrors.value.description">
          <Input id="agent-description" v-model="description" name="description" autocomplete="off" maxlength="500" />
        </FormField>
        <Button type="submit" class="sm:mt-[1.625rem]" :disabled="name.trim() === '' || createForm.pending.value">
          {{ createForm.pending.value ? t('agents.create.submitting') : t('agents.create.submit') }}
        </Button>
      </div>
    </form>

    <div v-if="loading" class="space-y-2" aria-busy="true">
      <Skeleton v-for="i in 2" :key="i" class="h-20 w-full" />
    </div>

    <EmptyState v-else-if="agents.length === 0" :icon="Bot" :title="t('agents.emptyTitle')" :description="t('agents.emptyDescription')" />

    <ul v-else class="space-y-3">
      <li v-for="agent in agents" :key="agent.id">
        <Card>
          <CardContent class="space-y-4 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="flex items-center gap-2 font-medium">
                  <Bot class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                  <span class="truncate">{{ agent.name }}</span>
                </p>
                <p v-if="agent.description" class="text-sm text-muted-foreground">{{ agent.description }}</p>
                <p class="text-xs text-muted-foreground">
                  {{ agent.owner ? t('agents.owner', { name: agent.owner.name }) : t('agents.noOwner') }}
                  · {{ t('agents.tokenCount', agent.token_count) }}
                </p>
              </div>
              <div v-if="canManage(agent)" class="flex items-center gap-2">
                <Button variant="outline" size="sm" :aria-expanded="openId === agent.id" @click="toggle(agent)">
                  <KeyRound class="mr-2 h-4 w-4" />{{ openId === agent.id ? t('agents.hide') : t('agents.manage') }}
                </Button>
                <Button
                  variant="ghost" size="sm" :disabled="list.pending.value"
                  :aria-label="`${t('agents.remove')}: ${agent.name}`" @click="removing = agent"
                >
                  <Trash2 class="h-4 w-4" />
                </Button>
              </div>
              <p v-else class="max-w-xs text-xs text-muted-foreground">{{ t('agents.notYours') }}</p>
            </div>

            <div v-if="openId === agent.id" class="space-y-4 border-t pt-4">
              <h2 class="text-sm font-medium">{{ t('agents.tokens.title') }}</h2>

              <Skeleton v-if="tokensLoading" class="h-10 w-full" />
              <p v-else-if="tokens.length === 0" class="text-sm text-muted-foreground">{{ t('agents.tokens.empty') }}</p>
              <ul v-else class="divide-y rounded-md border">
                <li v-for="token in tokens" :key="token.id" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
                  <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                      <span class="truncate">{{ token.name }}</span>
                      <Badge variant="secondary">{{ t(`agents.tokens.${token.access}`) }}</Badge>
                    </p>
                    <p class="text-xs text-muted-foreground">
                      {{ token.projects ? t('agents.tokens.limitedTo', { projects: token.projects.join(', ') }) : t('agents.tokens.allProjects') }}
                      · {{ token.last_used_at ? t('agents.tokens.lastUsed', { date: formatDateTime(token.last_used_at, locale) }) : t('agents.tokens.neverUsed') }}
                      · {{ token.expires_at ? t('agents.tokens.expires', { date: formatDateTime(token.expires_at, locale) }) : t('agents.tokens.noExpiry') }}
                    </p>
                  </div>
                  <Button
                    variant="outline" size="sm" :disabled="list.pending.value"
                    :aria-label="t('agents.tokens.revokeNamed', { name: token.name })" @click="revoke(agent, token)"
                  >
                    {{ t('agents.tokens.revoke') }}
                  </Button>
                </li>
              </ul>

              <template v-if="connections.length">
                <h2 class="text-sm font-medium">{{ t('agents.apps.title') }}</h2>
                <ul class="divide-y rounded-md border">
                  <li v-for="connection in connections" :key="connection.id" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3" data-testid="agent-app">
                    <div class="min-w-0">
                      <p class="truncate text-sm font-medium">{{ connection.app }}</p>
                      <p class="text-xs text-muted-foreground">{{ t('agents.apps.since', { date: formatDateTime(connection.connected_at, locale) }) }}</p>
                    </div>
                    <Button variant="outline" size="sm" :disabled="list.pending.value" :aria-label="t('agents.apps.disconnectNamed', { name: connection.app })" @click="disconnect(agent, connection)">
                      {{ t('agents.apps.disconnect') }}
                    </Button>
                  </li>
                </ul>
              </template>

              <form novalidate class="space-y-4 rounded-md bg-muted/40 p-4" @submit.prevent="createToken(agent)">
                <Alert v-if="tokenForm.error.value" variant="destructive" :title="tokenForm.error.value" />
                <div class="grid gap-4 sm:grid-cols-3 sm:items-start">
                  <FormField :id="`token-name-${agent.id}`" :label="t('agents.tokens.name')" :errors="tokenForm.fieldErrors.value.name">
                    <Input
                      :id="`token-name-${agent.id}`" v-model="draft.name" autocomplete="off" maxlength="80"
                      :placeholder="t('agents.tokens.namePlaceholder')"
                    />
                  </FormField>
                  <FormField :id="`token-access-${agent.id}`" :label="t('agents.tokens.access')" :errors="tokenForm.fieldErrors.value.access">
                    <Select
                      :id="`token-access-${agent.id}`" :model-value="draft.access" :options="accessOptions"
                      @update:model-value="draft.access = $event as 'read' | 'write'"
                    />
                  </FormField>
                  <FormField :id="`token-expiry-${agent.id}`" :label="t('agents.tokens.expiry')" :errors="tokenForm.fieldErrors.value.expires_in_days">
                    <Select
                      :id="`token-expiry-${agent.id}`" :model-value="draft.expiry" :options="expiryOptions"
                      @update:model-value="draft.expiry = $event"
                    />
                  </FormField>
                </div>

                <fieldset v-if="projects.length > 0" class="space-y-2">
                  <legend class="text-sm font-medium">{{ t('agents.tokens.projects') }}</legend>
                  <p class="text-xs text-muted-foreground">{{ t('agents.tokens.projectsHint') }}</p>
                  <div class="flex flex-wrap gap-x-5 gap-y-2">
                    <label v-for="project in projects" :key="project.key" class="flex items-center gap-2 text-sm">
                      <Checkbox
                        :id="`token-project-${agent.id}-${project.key}`" :model-value="draft.projects.includes(project.key)"
                        @update:model-value="toggleProject(project.key, $event === true)"
                      />
                      <span>{{ project.name }} <span class="text-muted-foreground">({{ project.key }})</span></span>
                    </label>
                  </div>
                  <p v-if="tokenForm.fieldErrors.value.projects?.length" class="text-sm text-destructive">
                    {{ tokenForm.fieldErrors.value.projects[0] }}
                  </p>
                </fieldset>

                <Button type="submit" size="sm" :disabled="draft.name.trim() === '' || tokenForm.pending.value">
                  {{ tokenForm.pending.value ? t('agents.tokens.creating') : t('agents.tokens.create') }}
                </Button>
              </form>
            </div>
          </CardContent>
        </Card>
      </li>
    </ul>

    <Dialog v-model:open="secretOpen" :title="t('agents.tokens.secretTitle')" :description="t('agents.tokens.secretDescription')">
      <div class="space-y-4">
        <Input
          :model-value="secret?.token ?? ''" readonly class="font-mono text-xs" :aria-label="t('agents.tokens.secretTitle')"
          @focus="($event.target as HTMLInputElement).select()"
        />
        <div class="flex justify-end gap-2">
          <Button variant="outline" @click="copySecret">
            <component :is="copied ? Check : Copy" class="mr-2 h-4 w-4" />
            {{ copied ? t('agents.tokens.copied') : t('agents.tokens.copy') }}
          </Button>
          <Button @click="closeSecret">{{ t('agents.tokens.done') }}</Button>
        </div>
      </div>
    </Dialog>

    <ConfirmDialog
      v-model:open="removeOpen"
      :title="t('agents.removeTitle', { name: removing?.name ?? '' })"
      :description="t('agents.removeDescription')"
      :confirm-label="t('agents.remove')"
      :busy="list.pending.value"
      destructive
      @confirm="confirmRemove"
    />
  </div>
</template>
