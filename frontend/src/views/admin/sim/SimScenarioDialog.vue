<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SimConfig, SimPack, SimScenario, SimStatus } from '@/api/simulator'
import { useApiForm } from '@/composables/useApiForm'
import * as api from '@/api/simulator'
import FormField from '@/components/app/FormField.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Checkbox from '@/components/ui/Checkbox.vue'
import Dialog from '@/components/ui/Dialog.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'

/** Creates or changes a scenario: everything a run is made of. */
interface Props { open: boolean; status: SimStatus; packs: SimPack[]; scenario: SimScenario | null }
const props = defineProps<Props>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [scenario: SimScenario] }>()

const { t } = useI18n()
const form = useApiForm()
const FLOWS = ['flowLifecycle', 'flowNotify', 'flowSearch', 'flowFeedback', 'flowDoc', 'flowIsolation', 'flowRealtime'] as const

const name = ref('')
const personas = ref('')
const personasError = ref<string | null>(null)
/** Numbers are kept as the text of their inputs and read when saving. */
const fields = reactive({
  teams: '', size_min: '', size_max: '', duration_minutes: '', ramp_up_seconds: '', real_signup: '', intensity: '', seed: '',
  p95_ms: '', error_rate: '', flow_success: '',
})
const speed = ref('10')
const dayMode = ref('workday')
const volume = ref('small')
const pack = ref('0')
const flows = reactive<Record<string, boolean>>({})

watch(() => props.open, (open) => {
  if (!open) return
  const config = props.scenario?.config ?? props.status.defaults
  const thresholds = { ...props.status.thresholds, ...config.thresholds }
  name.value = props.scenario?.name ?? ''
  Object.assign(fields, {
    teams: String(config.teams), size_min: String(config.size_min), size_max: String(config.size_max),
    duration_minutes: String(config.duration_minutes), ramp_up_seconds: String(config.ramp_up_seconds),
    real_signup: String(Math.round(config.real_signup_share * 100)), intensity: String(config.intensity), seed: String(config.seed),
    p95_ms: String(thresholds.p95_ms), error_rate: String(thresholds.error_rate * 100), flow_success: String(thresholds.flow_success * 100),
  })
  speed.value = String(config.speed)
  dayMode.value = config.day_mode
  volume.value = config.volume
  pack.value = String(config.pack_id ?? 0)
  for (const flow of FLOWS) flows[flow] = !(config.disabled_actions ?? []).includes(flow)
  personas.value = Object.keys(config.personas ?? {}).length ? JSON.stringify(config.personas, null, 2) : ''
  personasError.value = null
  form.clear()
}, { immediate: true })

const number = (text: string, fallback: number) => (Number.isFinite(Number(text)) && text.trim() !== '' ? Number(text) : fallback)
const options = (values: readonly (string | number)[], label: (value: string) => string) => values.map((v) => ({ value: String(v), label: label(String(v)) }))
const speeds = computed(() => options(props.status.speeds.filter((s) => s <= props.status.caps.speed), (v) => `${v}x`))

function config(): Partial<SimConfig> | null {
  let own: SimConfig['personas'] = {}
  personasError.value = null
  if (personas.value.trim() !== '') {
    try {
      own = JSON.parse(personas.value)
    } catch {
      personasError.value = t('sim.scenario.personasInvalid')
      return null
    }
  }
  const d = props.status.defaults
  return {
    teams: number(fields.teams, d.teams), size_min: number(fields.size_min, d.size_min), size_max: number(fields.size_max, d.size_max),
    speed: Number(speed.value), duration_minutes: number(fields.duration_minutes, d.duration_minutes), day_mode: dayMode.value as SimConfig['day_mode'],
    ramp_up_seconds: number(fields.ramp_up_seconds, d.ramp_up_seconds), real_signup_share: number(fields.real_signup, 0) / 100,
    volume: volume.value, intensity: number(fields.intensity, 1), seed: number(fields.seed, 1), pack_id: pack.value === '0' ? null : Number(pack.value),
    thresholds: {
      p95_ms: number(fields.p95_ms, props.status.thresholds.p95_ms),
      error_rate: number(fields.error_rate, props.status.thresholds.error_rate * 100) / 100,
      flow_success: number(fields.flow_success, props.status.thresholds.flow_success * 100) / 100,
    },
    disabled_actions: FLOWS.filter((flow) => !flows[flow]),
    personas: own,
  }
}

async function save() {
  const values = config()
  if (values === null) return
  await form.submit(async () => {
    const saved = props.scenario
      ? await api.updateScenario(props.scenario.id, name.value.trim(), values)
      : await api.createScenario(name.value.trim(), values)
    emit('saved', saved)
    emit('update:open', false)
  })
}
</script>

<template>
  <Dialog
    :open="open" class="max-h-[90vh] max-w-3xl overflow-y-auto" :title="t(scenario ? 'sim.scenario.edit' : 'sim.scenario.new')"
    :description="t('sim.scenario.description')" :close-label="t('common.close')" @update:open="emit('update:open', $event)"
  >
    <form class="space-y-5" @submit.prevent="save">
      <Alert v-if="form.error.value" variant="destructive" :title="form.error.value" />
      <FormField id="sim-name" :label="t('sim.scenario.name')" :errors="form.fieldErrors.value.name">
        <Input id="sim-name" v-model="name" required maxlength="80" />
      </FormField>

      <fieldset class="grid gap-4 sm:grid-cols-3">
        <legend class="mb-2 text-sm font-medium">{{ t('sim.scenario.teams') }}</legend>
        <FormField id="sim-teams" :label="t('sim.scenario.teamCount')" :hint="t('sim.scenario.upTo', { n: status.caps.teams })">
          <Input id="sim-teams" v-model="fields.teams" type="number" min="1" :max="status.caps.teams" />
        </FormField>
        <FormField id="sim-size-min" :label="t('sim.scenario.sizeMin')"><Input id="sim-size-min" v-model="fields.size_min" type="number" min="1" max="10" /></FormField>
        <FormField id="sim-size-max" :label="t('sim.scenario.sizeMax')"><Input id="sim-size-max" v-model="fields.size_max" type="number" min="1" max="10" /></FormField>
        <FormField id="sim-real" :label="t('sim.scenario.realSignup')" :hint="t('sim.scenario.realSignupHint')">
          <Input id="sim-real" v-model="fields.real_signup" type="number" min="0" max="100" />
        </FormField>
        <FormField id="sim-volume" :label="t('sim.scenario.volume')" :hint="t('sim.scenario.volumeHint')">
          <Select id="sim-volume" v-model="volume" :options="options(status.volumes, (v) => t(`sim.volume.${v}`))" />
        </FormField>
        <FormField id="sim-pack" :label="t('sim.scenario.pack')">
          <Select id="sim-pack" v-model="pack" :options="packs.map((p) => ({ value: String(p.id), label: p.name }))" />
        </FormField>
      </fieldset>

      <fieldset class="grid gap-4 sm:grid-cols-3">
        <legend class="mb-2 text-sm font-medium">{{ t('sim.scenario.pace') }}</legend>
        <FormField id="sim-speed" :label="t('sim.scenario.speed')" :hint="t('sim.scenario.speedHint')"><Select id="sim-speed" v-model="speed" :options="speeds" /></FormField>
        <FormField id="sim-minutes" :label="t('sim.scenario.minutes')" :hint="t('sim.scenario.upTo', { n: status.caps.duration_minutes })">
          <Input id="sim-minutes" v-model="fields.duration_minutes" type="number" min="1" :max="status.caps.duration_minutes" />
        </FormField>
        <FormField id="sim-day" :label="t('sim.scenario.dayMode')">
          <Select id="sim-day" v-model="dayMode" :options="options(['realistic', 'workday', 'continuous'], (v) => t(`sim.dayMode.${v}`))" />
        </FormField>
        <FormField id="sim-ramp" :label="t('sim.scenario.ramp')" :hint="t('sim.scenario.rampHint')"><Input id="sim-ramp" v-model="fields.ramp_up_seconds" type="number" min="0" /></FormField>
        <FormField id="sim-intensity" :label="t('sim.scenario.intensity')" :hint="t('sim.scenario.intensityHint')">
          <Input id="sim-intensity" v-model="fields.intensity" type="number" min="0.1" max="10" step="0.1" />
        </FormField>
        <FormField id="sim-seed" :label="t('sim.scenario.seed')" :hint="t('sim.scenario.seedHint')"><Input id="sim-seed" v-model="fields.seed" type="number" min="1" /></FormField>
      </fieldset>

      <fieldset class="grid gap-4 sm:grid-cols-3">
        <legend class="mb-2 text-sm font-medium">{{ t('sim.scenario.thresholds') }}</legend>
        <FormField id="sim-p95" :label="t('sim.scenario.p95')"><Input id="sim-p95" v-model="fields.p95_ms" type="number" min="1" /></FormField>
        <FormField id="sim-errors" :label="t('sim.scenario.errorRate')"><Input id="sim-errors" v-model="fields.error_rate" type="number" min="0" max="100" step="0.1" /></FormField>
        <FormField id="sim-flows" :label="t('sim.scenario.flowSuccess')"><Input id="sim-flows" v-model="fields.flow_success" type="number" min="0" max="100" step="0.1" /></FormField>
      </fieldset>

      <fieldset>
        <legend class="mb-2 text-sm font-medium">{{ t('sim.scenario.flows') }}</legend>
        <div class="grid gap-2 sm:grid-cols-2">
          <label v-for="flow in FLOWS" :key="flow" class="flex items-center gap-2 text-sm">
            <Checkbox :id="`sim-${flow}`" :model-value="flows[flow]" @update:model-value="flows[flow] = $event === true" />
            {{ t(`sim.flow.${flow}`) }}
          </label>
        </div>
      </fieldset>

      <FormField id="sim-personas" :label="t('sim.scenario.personas')" :hint="t('sim.scenario.personasHint')" :errors="personasError ? [personasError] : []">
        <Textarea id="sim-personas" v-model="personas" :rows="4" class="font-mono text-xs" placeholder="{ &quot;developer&quot;: { &quot;pause&quot;: 30, &quot;weights&quot;: { &quot;search&quot;: 10 } } }" />
      </FormField>

      <div class="flex justify-end gap-2">
        <Button type="button" variant="outline" @click="emit('update:open', false)">{{ t('common.cancel') }}</Button>
        <Button type="submit" :disabled="form.pending.value || name.trim() === ''">{{ t('common.save') }}</Button>
      </div>
    </form>
  </Dialog>
</template>
