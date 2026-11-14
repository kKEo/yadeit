<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/billing'
import type { BillingStatus } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { formatDate } from '@/lib/format'
import { leaveTo } from '@/lib/navigate'
import { useSlug } from '@/queries/keys'
import { useWorkspaceStore } from '@/stores/workspace'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import Progress from '@/components/ui/Progress.vue'

/**
 * The workspace's plan: what it allows, how much is used and, for owners, the
 * way to a paid plan. Renders nothing on an installation without plans.
 */
const { t, locale } = useI18n()
const route = useRoute()
const slug = useSlug()
const store = useWorkspaceStore()
const form = useApiForm()
const status = ref<BillingStatus | null>(null)

const isOwner = computed(() => store.role === 'owner')
const visible = computed(() => status.value !== null && (status.value.limits_enforced || status.value.billing_enabled))
const current = computed(() => status.value?.plans.find((p) => p.key === status.value?.plan) ?? null)
// Back from the payment page: Stripe confirms a little later, so this is a note and not a state.
const returned = computed(() => (route.query.billing === 'success' || route.query.billing === 'cancelled' ? route.query.billing : null))
const offers = computed(() => (status.value && isOwner.value && !status.value.subscription
  ? status.value.plans.filter((p) => p.purchasable && p.key !== status.value!.plan)
  : []))

const number = (value: number) => new Intl.NumberFormat(locale.value).format(value)
const rows = computed(() => {
  const s = status.value
  if (!s || !s.limits_enforced) return []
  return (['members', 'projects'] as const).map((key) => {
    const limit = s.limits[key]
    return { key, used: s.usage[key], limit, percent: limit === null || limit === 0 ? 0 : Math.min(100, Math.round((s.usage[key] / limit) * 100)) }
  })
})

async function go(target: () => Promise<string>) {
  let url: string | null = null
  await form.submit(async () => { url = await target() })
  if (url) leaveTo(url)
}

onMounted(() => form.submit(async () => { status.value = await api.getBilling(slug.value) }))
</script>

<template>
  <Card v-if="visible && status">
    <CardHeader class="flex-row items-start justify-between gap-4 space-y-0">
      <div class="space-y-1.5">
        <CardTitle class="text-lg">{{ t('billing.title') }}</CardTitle>
        <CardDescription>{{ t('billing.description') }}</CardDescription>
      </div>
      <Badge variant="secondary" data-testid="plan-name">{{ current?.name ?? status.plan }}</Badge>
    </CardHeader>
    <CardContent class="space-y-4">
      <Alert v-if="form.error.value" variant="destructive" :title="form.error.value" />
      <Alert v-else-if="returned === 'success'" variant="success" :title="t('billing.returnedSuccess')" />
      <Alert v-else-if="returned === 'cancelled'" :title="t('billing.returnedCancelled')" />

      <div v-for="row in rows" :key="row.key" class="space-y-1" data-testid="plan-usage">
        <div class="flex items-baseline justify-between text-sm">
          <span>{{ t(`billing.${row.key}`) }}</span>
          <span class="tabular-nums text-muted-foreground">
            {{ row.limit === null ? t('billing.unlimited', { used: number(row.used) }) : t('billing.usedOf', { used: number(row.used), limit: number(row.limit) }) }}
          </span>
        </div>
        <Progress v-if="row.limit !== null" :model-value="row.percent" :aria-label="t(`billing.${row.key}`)" />
      </div>
      <p v-if="status.limits_enforced" class="text-sm text-muted-foreground">
        {{ t('billing.membersNote') }}
        <template v-if="status.limits.ai_tokens !== null">{{ t('billing.aiNote', { n: number(status.limits.ai_tokens) }) }}</template>
      </p>

      <p v-if="status.subscription?.ends_at" class="text-sm">
        {{ t('billing.endsAt', { date: formatDate(status.subscription.ends_at.slice(0, 10), locale) }) }}
      </p>

      <div v-if="isOwner && (offers.length || status.has_billing_account)" class="flex flex-wrap items-center gap-2">
        <Button v-for="plan in offers" :key="plan.key" :disabled="form.pending.value" @click="go(() => api.startCheckout(slug, plan.key))">
          {{ t('billing.moveTo', { plan: plan.name }) }}
        </Button>
        <Button v-if="status.has_billing_account" variant="outline" :disabled="form.pending.value" @click="go(() => api.openPortal(slug))">
          {{ t('billing.manage') }}
        </Button>
      </div>
      <p v-else-if="!isOwner && status.billing_enabled" class="text-sm text-muted-foreground">{{ t('billing.ownersOnly') }}</p>
    </CardContent>
  </Card>
</template>
