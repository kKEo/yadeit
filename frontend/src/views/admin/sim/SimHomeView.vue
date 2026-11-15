<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/simulator'
import type { SimPack, SimPackContent, SimRun, SimScenario, SimStatus } from '@/api/simulator'
import { useApiForm } from '@/composables/useApiForm'
import { formatDateTime } from '@/lib/format'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Dialog from '@/components/ui/Dialog.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Table from '@/components/ui/Table.vue'
import TableBody from '@/components/ui/TableBody.vue'
import TableCell from '@/components/ui/TableCell.vue'
import TableHead from '@/components/ui/TableHead.vue'
import TableHeader from '@/components/ui/TableHeader.vue'
import TableRow from '@/components/ui/TableRow.vue'
import SimScenarioDialog from './SimScenarioDialog.vue'

/** The Traffic lab: scenarios, starting a run, and the runs so far. */
const { t, locale } = useI18n()
const router = useRouter()
const action = useApiForm()

const loaded = ref(false)
const status = ref<SimStatus | null>(null)
const scenarios = ref<SimScenario[]>([])
const runs = ref<SimRun[]>([])
const packs = ref<SimPack[]>([])

const chosen = ref('0')
const runName = ref('')
const editing = ref<SimScenario | null>(null)
const editorOpen = ref(false)
const deleting = ref<SimScenario | null>(null)
const started = ref<(SimRun & { runner_token: string }) | null>(null)
const preview = ref<{ pack: SimPack; content: SimPackContent } | null>(null)

const scenarioOptions = computed(() => [
  { value: '0', label: t('sim.start.defaults') },
  ...scenarios.value.map((s) => ({ value: String(s.id), label: s.name })),
])
const active = computed(() => runs.value.find((run) => run.active) ?? null)
const statusVariant = (run: SimRun) => (run.active ? 'info' : run.status === 'finished' ? 'secondary' : 'destructive')
const deleteOpen = computed({ get: () => deleting.value !== null, set: (open) => { if (!open) deleting.value = null } })
const tokenOpen = computed({ get: () => started.value !== null, set: (open) => { if (!open) openRun() } })
const previewOpen = computed({ get: () => preview.value !== null, set: (open) => { if (!open) preview.value = null } })

async function load() {
  await action.submit(async () => {
    status.value = await api.getStatus()
    if (status.value) [scenarios.value, runs.value, packs.value] = await Promise.all([api.listScenarios(), api.listRuns(), api.listPacks()])
  })
  loaded.value = true
}

async function start() {
  await action.submit(async () => {
    const run = await api.startRun({ scenario_id: chosen.value === '0' ? null : Number(chosen.value), ...(runName.value.trim() ? { name: runName.value.trim() } : {}) })
    // With a standing runner there is nothing to copy: go straight to the run.
    if (status.value?.standing_runner) await router.push({ name: 'admin-sim-run', params: { id: run.id } })
    else started.value = run
  })
}

function openRun() {
  const run = started.value
  started.value = null
  if (run) void router.push({ name: 'admin-sim-run', params: { id: run.id } })
}

function edit(scenario: SimScenario | null) {
  editing.value = scenario
  editorOpen.value = true
}

function saved(scenario: SimScenario) {
  scenarios.value = [...scenarios.value.filter((s) => s.id !== scenario.id), scenario].sort((a, b) => a.name.localeCompare(b.name))
}

async function remove() {
  const scenario = deleting.value
  if (!scenario) return
  await action.submit(async () => {
    await api.deleteScenario(scenario.id)
    scenarios.value = scenarios.value.filter((s) => s.id !== scenario.id)
    if (chosen.value === String(scenario.id)) chosen.value = '0'
  })
  deleting.value = null
}

async function showPack(pack: SimPack) {
  await action.submit(async () => { preview.value = { pack, content: await api.getPack(pack.id) } })
}

onMounted(load)
</script>

<template>
  <div class="space-y-8">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight">{{ t('sim.title') }}</h1>
      <p class="mt-1 max-w-3xl text-sm text-muted-foreground">{{ t('sim.intro') }}</p>
    </div>

    <Skeleton v-if="!loaded" class="h-40 w-full" />
    <Alert v-else-if="!status" variant="warning" :title="t('sim.off.title')">{{ t('sim.off.text') }}</Alert>
    <template v-else>
      <Alert v-if="action.error.value" variant="destructive" :title="action.error.value" />
      <Alert v-if="status.production" variant="warning" :title="t('sim.production.title')">{{ t('sim.production.text') }}</Alert>

      <section class="rounded-lg border p-4" :aria-label="t('sim.start.title')">
        <h2 class="text-sm font-medium">{{ t('sim.start.title') }}</h2>
        <p v-if="active" class="mt-2 text-sm" data-testid="active-run">
          {{ t('sim.start.running', { name: active.name }) }}
          <RouterLink :to="{ name: 'admin-sim-run', params: { id: active.id } }" class="font-medium underline underline-offset-4">{{ t('sim.start.open') }}</RouterLink>
        </p>
        <form v-else class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="start">
          <div class="w-64 space-y-1.5">
            <label for="sim-scenario" class="text-sm font-medium">{{ t('sim.start.scenario') }}</label>
            <Select id="sim-scenario" v-model="chosen" :options="scenarioOptions" />
          </div>
          <div class="w-64 space-y-1.5">
            <label for="sim-run-name" class="text-sm font-medium">{{ t('sim.start.name') }}</label>
            <Input id="sim-run-name" v-model="runName" maxlength="80" :placeholder="t('sim.start.namePlaceholder')" />
          </div>
          <Button type="submit" :disabled="action.pending.value">{{ t('sim.start.button') }}</Button>
          <p class="basis-full text-xs text-muted-foreground">
            {{ t(status.standing_runner ? 'sim.start.standing' : 'sim.start.noStanding') }}
          </p>
        </form>
      </section>

      <section class="space-y-3">
        <div class="flex items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">{{ t('sim.scenarios.title') }}</h2>
          <Button variant="outline" size="sm" @click="edit(null)">{{ t('sim.scenario.new') }}</Button>
        </div>
        <p v-if="scenarios.length === 0" class="text-sm text-muted-foreground">{{ t('sim.scenarios.empty') }}</p>
        <Table v-else>
          <TableHeader>
            <TableRow>
              <TableHead>{{ t('sim.scenario.name') }}</TableHead>
              <TableHead class="text-right">{{ t('sim.scenario.teamCount') }}</TableHead>
              <TableHead>{{ t('sim.scenarios.size') }}</TableHead>
              <TableHead>{{ t('sim.scenario.speed') }}</TableHead>
              <TableHead class="text-right">{{ t('sim.scenario.minutes') }}</TableHead>
              <TableHead>{{ t('sim.scenario.dayMode') }}</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="scenario in scenarios" :key="scenario.id" data-testid="scenario-row">
              <TableCell class="font-medium">{{ scenario.name }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ scenario.config.teams }}</TableCell>
              <TableCell class="tabular-nums">{{ scenario.config.size_min }}–{{ scenario.config.size_max }}</TableCell>
              <TableCell class="tabular-nums">{{ scenario.config.speed }}x</TableCell>
              <TableCell class="text-right tabular-nums">{{ scenario.config.duration_minutes }}</TableCell>
              <TableCell>{{ t(`sim.dayMode.${scenario.config.day_mode}`) }}</TableCell>
              <TableCell class="space-x-2 text-right">
                <Button variant="outline" size="sm" @click="edit(scenario)">{{ t('sim.scenarios.edit') }}</Button>
                <Button variant="outline" size="sm" @click="deleting = scenario">{{ t('common.delete') }}</Button>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </section>

      <section class="space-y-3">
        <h2 class="text-lg font-semibold">{{ t('sim.runs.title') }}</h2>
        <p v-if="runs.length === 0" class="text-sm text-muted-foreground">{{ t('sim.runs.empty') }}</p>
        <Table v-else>
          <TableHeader>
            <TableRow>
              <TableHead>{{ t('sim.runs.run') }}</TableHead>
              <TableHead>{{ t('admin.status.label') }}</TableHead>
              <TableHead>{{ t('sim.runs.verdict') }}</TableHead>
              <TableHead class="text-right">{{ t('sim.totals.requests') }}</TableHead>
              <TableHead class="text-right">p95</TableHead>
              <TableHead class="text-right">{{ t('sim.totals.failed_flows') }}</TableHead>
              <TableHead>{{ t('sim.runs.started') }}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="run in runs" :key="run.id" data-testid="run-row">
              <TableCell>
                <RouterLink :to="{ name: 'admin-sim-run', params: { id: run.id } }" class="font-medium underline-offset-4 hover:underline">#{{ run.id }} {{ run.name }}</RouterLink>
                <span class="block text-xs text-muted-foreground">{{ run.config.teams }} × {{ run.config.size_min }}–{{ run.config.size_max }}, {{ run.speed }}x</span>
              </TableCell>
              <TableCell><Badge :variant="statusVariant(run)">{{ t(`sim.status.${run.status}`) }}</Badge></TableCell>
              <TableCell>
                <Badge v-if="run.verdict" :variant="run.verdict === 'pass' ? 'success' : 'destructive'">{{ t(`sim.verdict.${run.verdict}`) }}</Badge>
              </TableCell>
              <TableCell class="text-right tabular-nums">{{ run.totals?.requests ?? '' }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ run.totals ? `${run.totals.p95_ms} ms` : '' }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ run.totals?.failed_flows ?? '' }}</TableCell>
              <TableCell class="whitespace-nowrap text-muted-foreground">{{ run.started_at ? formatDateTime(run.started_at, locale) : '' }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </section>

      <section class="space-y-3">
        <h2 class="text-lg font-semibold">{{ t('sim.packs.title') }}</h2>
        <p class="max-w-3xl text-sm text-muted-foreground">{{ t('sim.packs.intro') }}</p>
        <ul class="divide-y rounded-lg border">
          <li v-for="pack in packs" :key="pack.id" class="flex items-center justify-between gap-3 p-3 text-sm" data-testid="pack-row">
            <span>
              <span class="font-medium">{{ pack.name }}</span>
              <Badge variant="outline" class="ml-2">{{ t(`sim.packs.source.${pack.source}`) }}</Badge>
              <span v-if="pack.theme" class="block text-muted-foreground">{{ pack.theme }}</span>
            </span>
            <Button variant="outline" size="sm" @click="showPack(pack)">{{ t('sim.packs.preview') }}</Button>
          </li>
        </ul>
      </section>

      <SimScenarioDialog v-model:open="editorOpen" :status="status" :packs="packs" :scenario="editing" @saved="saved" />
    </template>

    <ConfirmDialog
      v-model:open="deleteOpen" destructive :title="t('sim.scenarios.deleteTitle', { name: deleting?.name ?? '' })"
      :description="t('sim.scenarios.deleteDescription')" :confirm-label="t('common.delete')" :busy="action.pending.value" @confirm="remove"
    />

    <Dialog v-model:open="tokenOpen" :title="t('sim.token.title')" :description="t('sim.token.description')" :close-label="t('common.close')">
      <pre v-if="started" class="overflow-x-auto rounded-md bg-muted p-3 text-xs" data-testid="runner-command">SIM_TARGET={{ origin }} SIM_TOKEN='{{ started.runner_token }}' node simulator/src/main.ts</pre>
      <p class="text-sm text-muted-foreground">{{ t('sim.token.hint') }}</p>
      <div class="flex justify-end"><Button @click="openRun">{{ t('sim.token.open') }}</Button></div>
    </Dialog>

    <Dialog v-model:open="previewOpen" class="max-h-[85vh] max-w-2xl overflow-y-auto" :title="preview?.pack.name ?? ''" :description="preview?.content.theme" :close-label="t('common.close')">
      <div v-if="preview" class="space-y-4 text-sm">
        <p class="text-muted-foreground">
          {{ t('sim.packs.counts', {
            people: preview.content.people.length, teams: preview.content.teams.length,
            items: preview.content.projects.reduce((n, p) => n + p.items.length, 0),
            comments: preview.content.comments.length, pages: preview.content.pages.length, feedback: preview.content.feedback.length,
          }) }}
        </p>
        <div v-for="project in preview.content.projects" :key="project.key">
          <h3 class="font-medium">{{ project.key }} · {{ project.name }}</h3>
          <ul class="mt-1 list-disc space-y-0.5 pl-5 text-muted-foreground">
            <li v-for="item in project.items.slice(0, 8)" :key="item.title">{{ item.title }}</li>
          </ul>
        </div>
      </div>
    </Dialog>
  </div>
</template>

<script lang="ts">
/** Where the runner should aim: this installation. */
const origin = typeof window === 'undefined' ? '' : window.location.origin
</script>
