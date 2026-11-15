<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useIntervalFn } from '@vueuse/core'
import * as api from '@/api/simulator'
import type { SimComparison, SimControl, SimRun, SimRunDetail, SimStatus } from '@/api/simulator'
import { useApiForm } from '@/composables/useApiForm'
import { formatDateTime } from '@/lib/format'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import LineChart from '@/components/admin/LineChart.vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Select from '@/components/ui/Select.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import Table from '@/components/ui/Table.vue'
import TableBody from '@/components/ui/TableBody.vue'
import TableCell from '@/components/ui/TableCell.vue'
import TableHead from '@/components/ui/TableHead.vue'
import TableHeader from '@/components/ui/TableHeader.vue'
import TableRow from '@/components/ui/TableRow.vue'

/**
 * One run: live while it is going on (asked for every two seconds, not
 * pushed, because realtime is one of the things under test), and its
 * report afterwards.
 */
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const action = useApiForm()

const id = computed(() => Number(route.params.id))
const detail = ref<SimRunDetail | null>(null)
const status = ref<SimStatus | null>(null)
const others = ref<SimRun[]>([])
const missing = ref(false)
const baseline = ref('')
const comparison = ref<SimComparison | null>(null)
const confirming = ref<'stop' | 'purge' | 'forget' | null>(null)

const run = computed(() => detail.value?.run ?? null)
const report = computed(() => detail.value?.report ?? null)
const timeline = computed(() => report.value?.timeline ?? [])
const playing = computed(() => (detail.value?.teams ?? []).filter((team) => team.status !== 'removed'))
const noRunner = computed(() => run.value?.active === true && !(detail.value?.runners ?? []).some((r) => r.alive))
const speeds = computed(() => (status.value?.speeds ?? []).filter((s) => s <= (status.value?.caps.speed ?? 100)))
const tiles = computed(() => {
  const totals = report.value?.totals
  return totals ? ([
    ['requests', totals.requests], ['requests_per_second', totals.requests_per_second], ['p50_ms', `${totals.p50_ms} ms`], ['p95_ms', `${totals.p95_ms} ms`],
    ['p99_ms', `${totals.p99_ms} ms`], ['error_rate', `${(totals.error_rate * 100).toFixed(1)}%`], ['flows', totals.flows], ['failed_flows', totals.failed_flows],
    ['findings', totals.findings], ['oldest_job_seconds', `${totals.oldest_job_seconds} s`],
  ] as const) : []
})
const series = (key: keyof (typeof timeline.value)[number]) => timeline.value.map((point) => Number(point[key]))
const baselines = computed(() => others.value.filter((other) => other.id !== id.value && other.totals !== null)
  .map((other) => ({ value: String(other.id), label: `#${other.id} ${other.name}` })))
const confirmOpen = computed({ get: () => confirming.value !== null, set: (open) => { if (!open) confirming.value = null } })
const personaLetter = (persona: string) => persona.slice(0, 1).toUpperCase()

async function refresh() {
  try {
    detail.value = await api.getRun(id.value)
  } catch {
    if (detail.value === null) missing.value = true
  }
}

const poll = useIntervalFn(refresh, 2000, { immediate: false })
watch(() => run.value?.active, (active) => (active ? poll.resume() : poll.pause()), { immediate: true })

async function control(change: SimControl) {
  await action.submit(async () => {
    await api.controlRun(id.value, change)
    await refresh()
  })
}

async function confirmed() {
  const what = confirming.value
  confirming.value = null
  await action.submit(async () => {
    if (what === 'stop') await api.stopRun(id.value)
    else await api.purgeRun(id.value, what === 'forget')
    if (what === 'forget') await router.push({ name: 'admin-sim' })
    else await refresh()
  })
}

async function compare(other: string) {
  baseline.value = other
  await action.submit(async () => { comparison.value = other ? await api.compareRuns(id.value, Number(other)) : null })
}

/** The whole report as a file, for keeping or for another tool. */
function download() {
  const link = document.createElement('a')
  link.href = URL.createObjectURL(new Blob([JSON.stringify(detail.value, null, 2)], { type: 'application/json' }))
  link.download = `traffic-lab-run-${id.value}.json`
  link.click()
  URL.revokeObjectURL(link.href)
}

onMounted(async () => {
  await refresh()
  void api.getStatus().then((value) => { status.value = value })
  void api.listRuns().then((value) => { others.value = value }).catch(() => {})
})
</script>

<template>
  <div class="space-y-6">
    <RouterLink :to="{ name: 'admin-sim' }" class="text-sm text-muted-foreground underline-offset-4 hover:underline">← {{ t('sim.title') }}</RouterLink>

    <Alert v-if="missing" variant="destructive" :title="t('sim.run.missing')" />
    <Skeleton v-else-if="!detail || !run || !report" class="h-64 w-full" />
    <template v-else>
      <header class="flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold tracking-tight">#{{ run.id }} {{ run.name }}</h1>
        <Badge :variant="run.active ? 'info' : run.status === 'finished' ? 'secondary' : 'destructive'" data-testid="run-status">{{ t(`sim.status.${run.status}`) }}</Badge>
        <Badge :variant="report.verdict === 'pass' ? 'success' : 'destructive'" data-testid="run-verdict">
          {{ t(run.active ? `sim.verdict.sofar_${report.verdict}` : `sim.verdict.${report.verdict}`) }}
        </Badge>
        <span class="text-sm text-muted-foreground">
          <template v-if="playing.length">{{ t('sim.run.shape', { teams: playing.length, members: playing.reduce((n, team) => n + team.members.length, 0), speed: run.speed }) }} · </template>
          <template v-if="run.started_at">{{ formatDateTime(run.started_at, locale) }}</template>
        </span>
      </header>

      <Alert v-if="action.error.value" variant="destructive" :title="action.error.value" />
      <Alert v-if="run.stop_reason && !run.active" :title="run.stop_reason" />
      <Alert v-if="noRunner" variant="warning" :title="t('sim.run.noRunner.title')" data-testid="no-runner">{{ t('sim.run.noRunner.text') }}</Alert>
      <Alert v-if="report.runner_saturated" variant="warning" :title="t('sim.run.saturated.title')">{{ t('sim.run.saturated.text') }}</Alert>
      <Alert v-if="report.reasons.length" variant="destructive" :title="t('sim.run.reasons')">
        <ul class="list-disc pl-5"><li v-for="reason in report.reasons" :key="reason">{{ reason }}</li></ul>
      </Alert>

      <section v-if="run.active" class="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-lg border p-4" :aria-label="t('sim.run.controls')">
        <div class="flex items-center gap-2">
          <span class="text-sm font-medium">{{ t('sim.scenario.speed') }}</span>
          <div class="inline-flex rounded-md border" role="group" :aria-label="t('sim.scenario.speed')">
            <button
              v-for="speed in speeds" :key="speed" type="button" :aria-pressed="run.speed === speed" :disabled="action.pending.value"
              class="px-3 py-1.5 text-sm tabular-nums first:rounded-l-md last:rounded-r-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              :class="run.speed === speed ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'"
              :data-testid="`speed-${speed}`" @click="control({ speed })"
            >{{ speed }}x</button>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <span class="text-sm font-medium">{{ t('sim.run.teams') }}</span>
          <Button variant="outline" size="sm" :disabled="action.pending.value || playing.length <= 1" :aria-label="t('sim.run.removeTeam')" data-testid="remove-team" @click="control({ remove_teams: 1 })">−</Button>
          <span class="w-6 text-center text-sm tabular-nums">{{ playing.length }}</span>
          <Button variant="outline" size="sm" :disabled="action.pending.value" :aria-label="t('sim.run.addTeam')" data-testid="add-team" @click="control({ add_teams: 1 })">+</Button>
        </div>
        <div class="ml-auto flex gap-2">
          <Button variant="outline" size="sm" :disabled="action.pending.value" data-testid="pause" @click="control({ paused: run.status !== 'paused' })">
            {{ t(run.status === 'paused' ? 'sim.run.resume' : 'sim.run.pause') }}
          </Button>
          <Button variant="destructive" size="sm" data-testid="stop" @click="confirming = 'stop'">{{ t('sim.run.stop') }}</Button>
        </div>
      </section>
      <section v-else class="flex flex-wrap items-center gap-2">
        <Button variant="outline" size="sm" @click="download">{{ t('sim.run.download') }}</Button>
        <Button v-if="!run.purged" variant="outline" size="sm" data-testid="purge" @click="confirming = 'purge'">{{ t('sim.run.purge') }}</Button>
        <Button variant="outline" size="sm" @click="confirming = 'forget'">{{ t('sim.run.forget') }}</Button>
        <span v-if="run.purged" class="text-sm text-muted-foreground">{{ t('sim.run.purged') }}</span>
      </section>

      <dl class="grid grid-cols-2 gap-3 sm:grid-cols-5">
        <div v-for="[key, value] in tiles" :key="key" class="rounded-lg border p-3">
          <dt class="text-xs text-muted-foreground">{{ t(`sim.totals.${key}`) }}</dt>
          <dd class="mt-1 text-xl font-semibold tabular-nums">{{ value }}</dd>
        </div>
      </dl>

      <div v-if="timeline.length > 1" class="grid gap-3 lg:grid-cols-2">
        <LineChart :title="t('sim.chart.requests')" unit="/s" :series="[{ label: t('sim.chart.requests'), values: series('requests_per_second'), class: 'text-sky-600' }]" />
        <LineChart
          :title="t('sim.chart.latency')" unit="ms" :series="[
            { label: 'p50', values: series('p50_ms'), class: 'text-emerald-600' },
            { label: 'p95', values: series('p95_ms'), class: 'text-amber-600' },
            { label: 'p99', values: series('p99_ms'), class: 'text-rose-600' },
          ]"
        />
        <LineChart
          :title="t('sim.chart.failures')" :series="[
            { label: t('sim.chart.failedRequests'), values: series('errors'), class: 'text-rose-600' },
            { label: t('sim.chart.failedFlows'), values: series('failed_flows'), class: 'text-violet-600' },
          ]"
        />
        <LineChart
          :title="t('sim.chart.backlog')" :series="[
            { label: t('sim.chart.jobs'), values: series('jobs_waiting'), class: 'text-amber-600' },
            { label: t('sim.chart.jobAge'), values: series('oldest_job_seconds'), class: 'text-rose-600' },
            { label: t('sim.chart.runnerLag'), values: series('runner_lag_ms').map((ms) => ms / 1000), class: 'text-slate-500' },
          ]"
        />
      </div>

      <section v-if="detail.teams.length" class="space-y-3">
        <h2 class="text-lg font-semibold">{{ t('sim.run.teams') }}</h2>
        <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <li v-for="team in detail.teams" :key="team.id" class="rounded-lg border p-3" :class="team.status === 'removed' ? 'opacity-50' : ''" data-testid="team">
            <div class="flex items-baseline justify-between gap-2">
              <span class="truncate text-sm font-medium">{{ team.name }}</span>
              <Badge variant="outline">{{ t(`sim.teamStatus.${team.status}`, team.status) }}</Badge>
            </div>
            <ul class="mt-2 space-y-1">
              <li v-for="member in team.members" :key="member.id" class="flex items-center gap-2 text-xs">
                <span
                  class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-muted font-medium"
                  :title="t(`sim.persona.${member.persona}`, member.persona)" aria-hidden="true"
                >{{ personaLetter(member.persona) }}</span>
                <span class="truncate">{{ member.name }} <span class="text-muted-foreground">· {{ t(`sim.persona.${member.persona}`, member.persona) }}, {{ member.role }}</span></span>
                <Badge v-if="member.kind !== 'scripted'" variant="secondary">{{ t(`sim.kind.${member.kind}`) }}</Badge>
                <span class="ml-auto shrink-0 font-mono" :class="member.action && member.action !== 'idle' ? 'text-foreground' : 'text-muted-foreground'">
                  {{ member.action ?? '' }}
                </span>
              </li>
            </ul>
          </li>
        </ul>
      </section>

      <section v-if="report.flows.length" class="space-y-3">
        <h2 class="text-lg font-semibold">{{ t('sim.run.flows') }}</h2>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{{ t('sim.run.flow') }}</TableHead>
              <TableHead class="text-right">{{ t('sim.table.count') }}</TableHead>
              <TableHead class="text-right">{{ t('sim.table.failed') }}</TableHead>
              <TableHead class="text-right">p50</TableHead>
              <TableHead class="text-right">p95</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="flow in report.flows" :key="flow.name" data-testid="flow-row">
              <TableCell class="font-medium">{{ t(`sim.flowName.${flow.name}`, flow.name) }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ flow.count }}</TableCell>
              <TableCell class="text-right tabular-nums" :class="flow.errors ? 'font-semibold text-destructive' : ''">{{ flow.errors }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ flow.p50_ms }} ms</TableCell>
              <TableCell class="text-right tabular-nums">{{ flow.p95_ms }} ms</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </section>

      <section v-if="detail.findings.length" class="space-y-3">
        <h2 class="text-lg font-semibold">{{ t('sim.run.findings') }}</h2>
        <ul class="divide-y rounded-lg border">
          <li v-for="finding in detail.findings" :key="finding.id" class="p-3 text-sm" data-testid="finding">
            <span class="font-medium">{{ t(`sim.flowName.${finding.flow}`, finding.flow) }}</span>
            <span v-if="finding.step" class="text-muted-foreground"> · {{ finding.step }}</span>
            <span class="float-right text-xs text-muted-foreground">{{ formatDateTime(finding.created_at, locale) }}</span>
            <p class="mt-1 break-words text-muted-foreground">{{ finding.message }}</p>
          </li>
        </ul>
      </section>

      <section v-if="report.endpoints.length" class="space-y-3">
        <h2 class="text-lg font-semibold">{{ t('sim.run.endpoints') }}</h2>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{{ t('sim.run.endpoint') }}</TableHead>
              <TableHead class="text-right">{{ t('sim.table.count') }}</TableHead>
              <TableHead class="text-right">{{ t('sim.table.failed') }}</TableHead>
              <TableHead class="text-right">p50</TableHead>
              <TableHead class="text-right">p95</TableHead>
              <TableHead class="text-right">p99</TableHead>
              <TableHead class="text-right">{{ t('sim.table.server') }}</TableHead>
              <TableHead class="text-right">{{ t('sim.table.db') }}</TableHead>
              <TableHead class="text-right">{{ t('sim.table.queries') }}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="endpoint in report.endpoints" :key="endpoint.name" data-testid="endpoint-row">
              <TableCell class="font-mono text-xs">{{ endpoint.name }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ endpoint.count }}</TableCell>
              <TableCell class="text-right tabular-nums" :class="endpoint.errors ? 'font-semibold text-destructive' : ''">{{ endpoint.errors }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ endpoint.p50_ms }}</TableCell>
              <TableCell class="text-right tabular-nums" :class="endpoint.p95_ms > report.thresholds.p95_ms ? 'font-semibold text-destructive' : ''">{{ endpoint.p95_ms }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ endpoint.p99_ms }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ endpoint.server_ms ?? '' }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ endpoint.db_ms ?? '' }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ endpoint.queries ?? '' }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
        <p class="text-xs text-muted-foreground">{{ t('sim.run.endpointsNote') }}</p>
      </section>

      <section v-if="baselines.length" class="space-y-3">
        <h2 class="text-lg font-semibold">{{ t('sim.compare.title') }}</h2>
        <Select
          :model-value="baseline" class="w-72" :aria-label="t('sim.compare.title')" :placeholder="t('sim.compare.choose')"
          :options="baselines" @update:model-value="compare($event)"
        />
        <Table v-if="comparison && comparison.endpoints.length">
          <TableHeader>
            <TableRow>
              <TableHead>{{ t('sim.run.endpoint') }}</TableHead>
              <TableHead class="text-right">p95</TableHead>
              <TableHead class="text-right">{{ t('sim.compare.before') }}</TableHead>
              <TableHead class="text-right">{{ t('sim.compare.change') }}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="row in comparison.endpoints" :key="row.name" data-testid="compare-row">
              <TableCell class="font-mono text-xs">{{ row.name }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ row.p95_ms }} ms</TableCell>
              <TableCell class="text-right tabular-nums">{{ row.baseline_p95_ms === null ? '' : `${row.baseline_p95_ms} ms` }}</TableCell>
              <TableCell class="text-right tabular-nums" :class="(row.change_ms ?? 0) > 0 ? 'text-destructive' : (row.change_ms ?? 0) < 0 ? 'text-green-600' : ''">
                <template v-if="row.change_ms !== null">{{ row.change_ms > 0 ? '+' : '' }}{{ row.change_ms }} ms<template v-if="row.change_percent !== null"> ({{ row.change_percent > 0 ? '+' : '' }}{{ row.change_percent }}%)</template></template>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </section>

      <section v-if="detail.runners.length" class="space-y-2">
        <h2 class="text-lg font-semibold">{{ t('sim.run.runners') }}</h2>
        <ul class="flex flex-wrap gap-2 text-sm">
          <li v-for="runner in detail.runners" :key="runner.id" class="rounded-md border px-3 py-1.5">
            <span class="mr-1 inline-block h-2 w-2 rounded-full" :class="runner.alive ? 'bg-green-500' : 'bg-muted-foreground'" aria-hidden="true" />
            {{ runner.name }}<template v-if="runner.host"> @ {{ runner.host }}</template>
            <span class="text-muted-foreground"> · {{ t(runner.alive ? 'sim.run.runnerAlive' : 'sim.run.runnerGone') }}, {{ t('sim.run.lag', { ms: runner.lag_ms }) }}</span>
          </li>
        </ul>
      </section>
    </template>

    <ConfirmDialog
      v-model:open="confirmOpen" destructive :title="confirming ? t(`sim.confirm.${confirming}.title`) : ''"
      :description="confirming ? t(`sim.confirm.${confirming}.text`) : ''" :confirm-label="confirming ? t(`sim.confirm.${confirming}.button`) : ''"
      :busy="action.pending.value" @confirm="confirmed"
    />
  </div>
</template>
